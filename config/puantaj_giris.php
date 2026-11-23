<?php
// =========================================================
// config/puantaj_giris.php — Aylık Puantaj › "Puantaj Girişi" sekmesi (v554)
//
// BAĞIMSIZ, GÖSTERMELİK / GAYRİ RESMİ puantaj: kullanıcının kendi tanımladığı kişilere elle gün işareti.
// PDKS, hakediş, kart, `employees` ve diğer modüllerle bağı yoktur (okumaz, yazmaz); TEK İSTİSNA (v562): kişinin «bağlı çavuşu»
// (`puantaj_giris_kisiler.cavus_id` → `foremen.id`) — `foremen` YALNIZ OKUNUR (ad listesi), hiçbir şey yazılmaz, hakedişe etkisi yoktur.
// Tablolar: `puantaj_giris_kisiler`, `puantaj_giris_gunler`. Şema YALNIZ migrate.php "Puantaj Girişi"
// kartından kurulur (pg_migrate); sayfalar/yazma ucu DDL ATMAZ, tablo yoksa pg_hazir() false döner.
// Yazan TEK uç: puantaj_giris_kaydet.php (kapılar + audit orada). Bu dosya: kodlar, doğrulayıcılar,
// okuyucular, saf tablo kurucu (pg_tablo_kur → diğer sekmelerle AYNI "tablo" yapısı, tur='giris'),
// dışa aktarım satırları ve yazma yardımcıları. Saf fonksiyonlar DB/oturum bilmez → smoke testi.
// pc_* (ay/gün yardımcıları) ve pi_* (tarih doğrulama) çağrı anında config/puantaj_cizelgesi.php'den gelir.
// =========================================================
declare(strict_types=1);

const PG_AD_MAX = 120;
const PG_GOREV_MAX = 80;

/**
 * Giriş kodları. Harf + renk birlikte. agirlik: çalışılan gün katkısı (X=1, Y=0,5). grup: özet sütunu eşlemesi.
 * D İ Ü R T mevcut elle-işaret sözlüğünden (pi_kodlar) gelir; X ve Y bu sekmeye özgüdür.
 */
function pg_kodlar(): array
{
    $k = [
        'X' => ['ad' => 'Çalıştı',    'sinif' => 'cx', 'grup' => 'calisma', 'agirlik' => 1.0],
        'Y' => ['ad' => 'Yarım gün',  'sinif' => 'y',  'grup' => 'calisma', 'agirlik' => 0.5],
    ];
    foreach (pi_kodlar() as $kod => $kd) $k[$kod] = $kd + ['agirlik' => 0.0];
    return $k;
}

function pg_kod_gecerli($kod): bool
{
    return is_string($kod) && $kod !== '' && isset(pg_kodlar()[$kod]);
}

/** Kontrol karakterleri atılır, boşluklar tek boşluğa iner, kırpılır. */
function pg_metin_temizle($s): string
{
    if (!is_string($s)) return '';
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

/** Ad: 1–120 karakter. Geçersizse (boş / fazla uzun) null. */
function pg_ad_dogrula($s): ?string
{
    $a = pg_metin_temizle($s);
    if ($a === '' || mb_strlen($a, 'UTF-8') > PG_AD_MAX) return null;
    return $a;
}

/** Görev (ünvan/not): en çok 80 karakter; boş '' döner. Fazla uzunsa null. */
function pg_gorev_dogrula($s): ?string
{
    $g = pg_metin_temizle($s);
    if (mb_strlen($g, 'UTF-8') > PG_GOREV_MAX) return null;
    return $g;
}

// ── Şema (yalnız migrate.php kartından çağrılır) ────────────

/** Tablo ekler; v562: mevcut kişi tablosuna `cavus_id` kolonu pg_migrate ile eklenir (yabancı anahtar YOK). SQLite çeviricisiyle uyumlu yazım. @return array<string,string> */
function pg_tablolar(): array
{
    return [
        'puantaj_giris_kisiler' => "CREATE TABLE IF NOT EXISTS `puantaj_giris_kisiler` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `ad`         VARCHAR(120)  NOT NULL,
  `gorev`      VARCHAR(80)   NULL DEFAULT NULL,
  `cavus_id`   INT           NULL DEFAULT NULL,
  `depo`       VARCHAR(120)  NOT NULL DEFAULT '',
  `aktif`      TINYINT(1)    NOT NULL DEFAULT 1,
  `sira`       INT           NOT NULL DEFAULT 0,
  `created_by` INT           NULL DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME      NULL DEFAULT NULL,
  KEY `idx_pg_kisi_depo` (`depo`, `aktif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
        'puantaj_giris_gunler' => "CREATE TABLE IF NOT EXISTS `puantaj_giris_gunler` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `kisi_id`    INT           NOT NULL,
  `tarih`      DATE          NOT NULL,
  `kod`        VARCHAR(4)    NOT NULL,
  `user_id`    INT           NULL DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME      NULL DEFAULT NULL,
  UNIQUE KEY `uq_pg_gun` (`kisi_id`, `tarih`),
  KEY `idx_pg_gun_tarih` (`tarih`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
    ];
}

/** İki tablo da var mı? (Yalnız true önbelleğe alınır; hata → false, sayfa çökmez.) */
function pg_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        foreach (array_keys(pg_tablolar()) as $t) $pdo->query("SELECT 1 FROM `$t` LIMIT 0");
        return $var[$k] = true;
    } catch (Throwable $e) {
        return false;
    }
}

/** v562: kişi tablosunda `cavus_id` kolonu var mı? (Yalnız true önbelleğe alınır; yoksa «bağlı çavuş» arayüzü tamamen gizlenir.) */
function pg_cavus_kolon_var(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        $pdo->query('SELECT cavus_id FROM puantaj_giris_kisiler LIMIT 0');
        return $var[$k] = true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Tabloları ekler (IF NOT EXISTS) + v562: kişi tablosuna `cavus_id` kolonunu ekler (yoksa). Yalnız migrate.php kartından çağrılır.
 * TEST KANCASI: $GLOBALS['_pg_ddl_calistir'] = fn(PDO,string $ad,string $ddl): void.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function pg_migrate(PDO $pdo): array
{
    $rapor = [];
    $kanca = $GLOBALS['_pg_ddl_calistir'] ?? null;
    foreach (pg_tablolar() as $ad => $ddl) {
        $var = false;
        try { $pdo->query("SELECT 1 FROM `$ad` LIMIT 0"); $var = true; } catch (Throwable $e) { $var = false; }
        if ($var) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            if (is_callable($kanca)) $kanca($pdo, $ad, $ddl); else $pdo->exec($ddl);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
        } catch (Throwable $e) {
            error_log('[pg_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    // v562: «bağlı çavuş» kolonu (eski kurulumlarda yok). Yalnız ekler; mevcut veriye dokunmaz.
    $kol = 'puantaj_giris_kisiler.cavus_id';
    try { $pdo->query('SELECT 1 FROM `puantaj_giris_kisiler` LIMIT 0'); $tabloVar = true; } catch (Throwable $e) { $tabloVar = false; }
    if ($tabloVar) {
        $kolVar = false;
        try { $pdo->query('SELECT `cavus_id` FROM `puantaj_giris_kisiler` LIMIT 0'); $kolVar = true; } catch (Throwable $e) { $kolVar = false; }
        if ($kolVar) {
            $rapor[] = ['tablo' => $kol, 'durum' => 'var', 'mesaj' => 'Kolon zaten mevcut.'];
        } else {
            try {
                $ddl = 'ALTER TABLE `puantaj_giris_kisiler` ADD COLUMN `cavus_id` INT NULL DEFAULT NULL';
                if (is_callable($kanca)) $kanca($pdo, $kol, $ddl); else $pdo->exec($ddl);
                $rapor[] = ['tablo' => $kol, 'durum' => 'olusturuldu', 'mesaj' => 'Kolon eklendi.'];
            } catch (Throwable $e) {
                error_log('[pg_migrate] ' . $kol . ': ' . $e->getMessage());
                $rapor[] = ['tablo' => $kol, 'durum' => 'hata', 'mesaj' => 'Kolon eklenemedi (ayrıntı sunucu günlüğünde).'];
            }
        }
    }
    return $rapor;
}

// ── Okuyucular ──────────────────────────────────────────────

// ── Bağlı çavuş (v562) ──────────────────────────────────────

/** `foremen` (YALNIZ OKUNUR): [['id'=>int,'ad'=>string,'aktif'=>int],…] — aktifler önce, ad A-Z. Tablo yoksa/hata → []. */
function pg_cavuslar_oku(PDO $pdo): array
{
    try {
        $out = [];
        foreach ($pdo->query('SELECT id, name, is_active FROM foremen')->fetchAll() as $r) {
            $out[] = ['id' => (int)$r['id'], 'ad' => (string)$r['name'], 'aktif' => (int)$r['is_active'] === 1 ? 1 : 0];
        }
        usort($out, static fn($a, $b) => $b['aktif'] <=> $a['aktif'] ?: strcmp(pc_fold($a['ad']), pc_fold($b['ad'])) ?: $a['id'] <=> $b['id']);
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Kişi satırlarına 'cavus' (ad) + 'cavus_pasif' ekler. cavus_id yoksa/çavuş bulunamazsa ''. */
function pg_cavus_adlari_ekle(PDO $pdo, array $kisiler): array
{
    $gerek = false;
    foreach ($kisiler as $k) if (!empty($k['cavus_id'])) { $gerek = true; break; }
    $harita = [];
    if ($gerek) foreach (pg_cavuslar_oku($pdo) as $c) $harita[$c['id']] = $c;
    foreach ($kisiler as $i => $k) {
        $c = !empty($k['cavus_id']) ? ($harita[(int)$k['cavus_id']] ?? null) : null;
        $kisiler[$i]['cavus'] = $c ? $c['ad'] : '';
        $kisiler[$i]['cavus_pasif'] = $c ? $c['aktif'] === 0 : false;
    }
    return $kisiler;
}

/** SELECT kolon listesi: `cavus_id` yalnız kolon varsa. */
function pg_kisi_kolonlar(PDO $pdo): string
{
    return 'id, ad, gorev, depo, aktif, sira' . (pg_cavus_kolon_var($pdo) ? ', cavus_id' : '');
}

/** Ham satır → kişi dizisi. */
function pg_kisi_satir(array $r): array
{
    return ['id' => (int)$r['id'], 'ad' => (string)$r['ad'], 'gorev' => (string)($r['gorev'] ?? ''), 'depo' => (string)($r['depo'] ?? ''), 'aktif' => (int)$r['aktif'], 'sira' => (int)$r['sira'],
        'cavus_id' => isset($r['cavus_id']) && (int)$r['cavus_id'] > 0 ? (int)$r['cavus_id'] : null];
}

/**
 * Çavuş alanı doğrulama. $v: null | '' | 0 → bağ yok (id null); pozitif tamsayı → `foremen`de var olmalı ve AKTİF olmalı
 * (kişinin zaten bağlı olduğu çavuş $mevcut pasif olsa da değişmeden kalabilir). @return array{ok:bool,id:?int,hata:?string}
 */
function pg_cavus_dogrula(PDO $pdo, $v, ?int $mevcut = null): array
{
    if ($v === null || $v === '' || $v === 0 || $v === '0') return ['ok' => true, 'id' => null, 'hata' => null];
    if (!(is_int($v) || (is_string($v) && ctype_digit($v))) || (int)$v <= 0) return ['ok' => false, 'id' => null, 'hata' => 'Geçersiz çavuş.'];
    $id = (int)$v;
    foreach (pg_cavuslar_oku($pdo) as $c) {
        if ($c['id'] !== $id) continue;
        if ($c['aktif'] === 1 || $id === $mevcut) return ['ok' => true, 'id' => $id, 'hata' => null];
        return ['ok' => false, 'id' => null, 'hata' => 'Pasif çavuşa bağlanamaz.'];
    }
    return ['ok' => false, 'id' => null, 'hata' => 'Çavuş bulunamadı.'];
}

/** Çavuş süzgeci: '' = hepsi, 'yok' = çavuşsuz, sayı = o çavuşa bağlı olanlar. */
function pg_cavus_suz(array $kisiler, string $f): array
{
    if ($f === '') return $kisiler;
    if ($f === 'yok') return array_values(array_filter($kisiler, static fn($k) => empty($k['cavus_id'])));
    $id = (int)$f;
    return array_values(array_filter($kisiler, static fn($k) => (int)($k['cavus_id'] ?? 0) === $id));
}

/** GET parametresi → '' | 'yok' | pozitif tamsayı metni. */
function pg_cavus_suz_dogrula($v): string
{
    if (!is_string($v)) return '';
    if ($v === 'yok') return 'yok';
    return ctype_digit($v) && (int)$v > 0 ? (string)(int)$v : '';
}

/**
 * Kişiler (depo kuralı: depo_sql_in — atanmamış-depo geçer). $pasifDahil false → yalnız aktifler.
 * Sıra: sira, Türkçe duyarsız ad, id. Hata fırlatabilir.
 * @return list<array{id:int,ad:string,gorev:string,depo:string,aktif:int,sira:int}>
 */
function pg_kisiler_oku(PDO $pdo, bool $pasifDahil = false): array
{
    $where = ['1=1']; $params = [];
    if (!$pasifDahil) $where[] = 'aktif = 1';
    [$dsql, $dparams] = function_exists('depo_sql_in') ? depo_sql_in('depo') : ['', []];
    if ($dsql !== '') { $where[] = $dsql; $params = array_merge($params, $dparams); }
    $st = $pdo->prepare('SELECT ' . pg_kisi_kolonlar($pdo) . ' FROM puantaj_giris_kisiler WHERE ' . implode(' AND ', $where));
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[] = pg_kisi_satir($r);
    usort($out, static fn($a, $b) => $a['sira'] <=> $b['sira'] ?: strcmp(pc_fold($a['ad']), pc_fold($b['ad'])) ?: $a['id'] <=> $b['id']);
    return pg_cavus_adlari_ekle($pdo, $out);
}

/** Tek kişi (depo süzgeci YOK — çağıran depot_visible_to_user ile denetler) ya da null. */
function pg_kisi_bul(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    try {
        $st = $pdo->prepare('SELECT ' . pg_kisi_kolonlar($pdo) . ' FROM puantaj_giris_kisiler WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? pg_cavus_adlari_ekle($pdo, [pg_kisi_satir($r)])[0] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Ayın işaretleri: [kisi_id => [tarih => kod]]. Okunamazsa boş dizi. Kişi süzgeci çağıranın satır kümesindedir. */
function pg_gunler_oku(PDO $pdo, string $ay): array
{
    $ay = pc_ay_dogrula($ay);
    try {
        $st = $pdo->prepare('SELECT kisi_id, tarih, kod FROM puantaj_giris_gunler WHERE tarih >= ? AND tarih < ? ORDER BY kisi_id ASC, tarih ASC');
        $st->execute([$ay . '-01', pc_ay_gez($ay, 1) . '-01']);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            if (!pg_kod_gecerli((string)$r['kod'])) continue;
            $out[(int)$r['kisi_id']][substr((string)$r['tarih'], 0, 10)] = (string)$r['kod'];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[puantaj_giris] oku: ' . $e->getMessage());
        return [];
    }
}

// ── Saf tablo kurucu ────────────────────────────────────────

/**
 * Kişiler + işaretler → diğer sekmelerle AYNI yapıda tablo (tur='giris').
 * satir: id, ad, alt (= görev), pasif, hucre[tarih] = ['k'=>kod,'d'=>ağırlık,'isaret'=>true,…], ozet [gun(float),izin,rapor,devamsiz].
 * gun_say[tarih] = o gün X ya da Y olan kişi sayısı. genel: kisi, gun, izin, rapor, devamsiz.
 * $kisiler: pg_kisiler_oku() satırları; $gunler: pg_gunler_oku() çıktısı.
 */
function pg_tablo_kur(string $ay, array $kisiler, array $gunler, string $q = ''): array
{
    $ay = pc_ay_dogrula($ay);
    $gunListe = pc_gunler($ay);
    $kodlar = pg_kodlar();
    $satirlar = [];
    foreach ($kisiler as $kisi) {
        $id = (int)$kisi['id'];
        $hucre = [];
        foreach (($gunler[$id] ?? []) as $tarih => $kod) {
            if (strncmp((string)$tarih, $ay, 7) !== 0 || !isset($kodlar[$kod])) continue;
            $hucre[(string)$tarih] = ['k' => $kod, 'd' => (float)$kodlar[$kod]['agirlik'], 'ilk' => null, 'son' => null, 'acik' => 0, 'isaret' => true];
        }
        $satirlar[] = ['id' => $id, 'ad' => (string)$kisi['ad'], 'alt' => (string)($kisi['gorev'] ?? ''), 'pasif' => (int)($kisi['aktif'] ?? 1) === 0, 'hucre' => $hucre,
            'cavus_id' => $kisi['cavus_id'] ?? null, 'cavus' => (string)($kisi['cavus'] ?? ''), 'cavus_pasif' => !empty($kisi['cavus_pasif'])];
    }
    $satirlar = pc_satir_filtre($satirlar, $q);

    $gunSay = [];
    foreach ($gunListe as $g) $gunSay[$g['tarih']] = 0;
    $genel = ['kisi' => count($satirlar), 'gun' => 0.0, 'izin' => 0, 'rapor' => 0, 'devamsiz' => 0];
    foreach ($satirlar as &$s) {
        $oz = ['gun' => 0.0, 'izin' => 0, 'rapor' => 0, 'devamsiz' => 0];
        foreach ($s['hucre'] as $tarih => $h) {
            if (!isset($gunSay[$tarih])) continue;
            $kd = $kodlar[$h['k']];
            if ($kd['agirlik'] > 0) { $oz['gun'] += $kd['agirlik']; $gunSay[$tarih]++; }
            elseif ($kd['grup'] === 'izin') $oz['izin']++;
            elseif ($kd['grup'] === 'rapor') $oz['rapor']++;
            elseif ($kd['grup'] === 'devamsiz') $oz['devamsiz']++;
        }
        $s['ozet'] = $oz;
        foreach ($oz as $k => $v) $genel[$k] += $v;
    }
    unset($s);
    return ['tur' => 'giris', 'ay' => $ay, 'gunler' => $gunListe, 'satirlar' => $satirlar, 'gun_say' => $gunSay, 'genel' => $genel];
}

/** Ekran/yazdır/Excel için tablo ya da ['hata'=>…]. Tablo yoksa 'sema'=>true (yalnız yönetici bağlantı görür). Sayfa 500 vermez. */
function pg_tablo(PDO $pdo, string $ay, string $q = '', bool $pasif = false, string $cavus = ''): array
{
    if (!pg_hazir($pdo)) return ['hata' => 'Puantaj Girişi tabloları henüz kurulmamış.', 'sema' => true];
    try {
        $hepsi = pg_kisiler_oku($pdo, true);
        $kisiler = $pasif ? $hepsi : array_values(array_filter($hepsi, static fn($k) => $k['aktif'] === 1));
        $cavusVar = pg_cavus_kolon_var($pdo);
        if ($cavusVar) $kisiler = pg_cavus_suz($kisiler, pg_cavus_suz_dogrula($cavus));
        $t = pg_tablo_kur($ay, $kisiler, pg_gunler_oku($pdo, $ay), $q);
        $t['toplam_kisi'] = count($hepsi);
        $t['cavus_goster'] = $cavusVar;   // v562: «bağlı çavuş» kolonu kurulu → dışa aktarım/yazdırma Çavuş sütunu taşır
        return $t;
    } catch (Throwable $e) {
        error_log('[puantaj_giris] tablo: ' . $e->getMessage());
        return ['hata' => 'Puantaj girişi şu anda okunamadı.'];
    }
}

// ── Dışa aktarım (ekranla AYNI veri) ────────────────────────

function pg_disa_baslik(array $tablo): array
{
    $b = ['Ad Soyad', 'Görev'];
    if (!empty($tablo['cavus_goster'])) $b[] = 'Çavuş';
    foreach ($tablo['gunler'] as $g) $b[] = $g['n'] . ' ' . $g['ad'];
    return array_merge($b, ['Çalışılan Gün', 'İzin', 'Rapor', 'Devamsız']);
}

/** Veri satırları + "o gün çalışan" + genel toplam. Gün hücresi = kod harfi (boş = null). Çalışılan gün 1 ondalık. */
function pg_disa_satirlar(array $tablo): array
{
    $out = [];
    $cg = !empty($tablo['cavus_goster']);
    foreach ($tablo['satirlar'] as $s) {
        $r = [$s['ad'], $s['alt']];
        if ($cg) $r[] = (string)($s['cavus'] ?? '');
        foreach ($tablo['gunler'] as $g) $r[] = isset($s['hucre'][$g['tarih']]) ? (string)$s['hucre'][$g['tarih']]['k'] : null;
        $oz = $s['ozet'];
        array_push($r, round((float)$oz['gun'], 1), $oz['izin'], $oz['rapor'], $oz['devamsiz']);
        $out[] = $r;
    }
    $say = $cg ? ['O gün çalışan', '', ''] : ['O gün çalışan', ''];
    foreach ($tablo['gunler'] as $g) $say[] = $tablo['gun_say'][$g['tarih']] ?? 0;
    array_push($say, '', '', '', '');
    $out[] = $say;
    $ge = $tablo['genel'];
    $genel = $cg ? ['GENEL TOPLAM (' . $ge['kisi'] . ' kişi)', '', ''] : ['GENEL TOPLAM (' . $ge['kisi'] . ' kişi)', ''];
    foreach ($tablo['gunler'] as $_) $genel[] = null;
    array_push($genel, round((float)$ge['gun'], 1), $ge['izin'], $ge['rapor'], $ge['devamsiz']);
    $out[] = $genel;
    return $out;
}

function pg_xlsx_sayfa(array $tablo, string $aciklama = ''): array
{
    $sut = [['baslik' => 'Ad Soyad'], ['baslik' => 'Görev']];
    if (!empty($tablo['cavus_goster'])) $sut[] = ['baslik' => 'Çavuş'];
    foreach ($tablo['gunler'] as $g) $sut[] = ['baslik' => $g['n'] . ' ' . $g['ad'], 'tip' => 'sayi'];
    $sut[] = ['baslik' => 'Çalışılan Gün', 'tip' => 'ondalik'];
    foreach (['İzin', 'Rapor', 'Devamsız'] as $b) $sut[] = ['baslik' => $b, 'tip' => 'tamsayi'];
    return [
        'ad' => 'Puantaj Girişi',
        'baslik' => 'Puantaj Girişi (gayri resmi) — ' . pc_ay_basligi($tablo['ay']),
        'aciklama' => $aciklama,
        'sutunlar' => $sut,
        'satirlar' => pg_disa_satirlar($tablo),
        'toplam' => false,
    ];
}

/** Hücre gösterimi (pc_hucre_goster 'giris' kolu): [css sınıfı, iç HTML, title]. */
function pg_hucre_goster(?array $h): array
{
    if ($h === null) return ['pc-b', '·', ''];
    $kd = pg_kodlar()[$h['k']] ?? ['ad' => (string)$h['k'], 'sinif' => 'd', 'agirlik' => 0.0];
    $ic = '<b>' . htmlspecialchars((string)$h['k'], ENT_QUOTES, 'UTF-8') . '</b>';
    if ($kd['agirlik'] > 0 && $kd['agirlik'] < 1) $ic .= '<i>½</i>';
    return ['pc-k pc-k-' . $kd['sinif'], $ic, $kd['ad']];
}

/** Sayı gösterimi: çalışılan gün için en çok 1 ondalık, ",0" atılır. */
function pg_gun_fmt($v): string
{
    return pc_sayi_fmt($v);
}

// ── Yazma yardımcıları (kapılar + audit çağıran uçtadır) ───

/**
 * Yeni kişi. Dönüş: ['ok','hata','id','audit'=>[action,id,eski,yeni]]. Ad/görev burada da doğrulanır.
 * Audit değerleri KİŞİ ADINI içermez (yalnız aktif, depo, uzunluklar).
 */
function pg_kisi_ekle(PDO $pdo, string $ad, string $gorev, string $depo, ?int $userId, $cavus = null): array
{
    $a = pg_ad_dogrula($ad); $g = pg_gorev_dogrula($gorev);
    $cv = pg_cavus_dogrula($pdo, $cavus);
    if (!$cv['ok']) return ['ok' => false, 'hata' => (string)$cv['hata'], 'id' => 0, 'audit' => null];
    $kolonVar = pg_cavus_kolon_var($pdo);
    if ($cv['id'] !== null && !$kolonVar) return ['ok' => false, 'hata' => 'Bağlı çavuş için migrate.php › Puantaj Girişi kartının çalıştırılması gerekiyor.', 'id' => 0, 'audit' => null];
    if ($a === null) return ['ok' => false, 'hata' => 'Ad 1 ile ' . PG_AD_MAX . ' karakter arasında olmalıdır.', 'id' => 0, 'audit' => null];
    if ($g === null) return ['ok' => false, 'hata' => 'Görev en çok ' . PG_GOREV_MAX . ' karakter olabilir.', 'id' => 0, 'audit' => null];
    $simdi = date('Y-m-d H:i:s');
    try {
        if ($kolonVar) {
            $pdo->prepare('INSERT INTO puantaj_giris_kisiler (ad, gorev, cavus_id, depo, aktif, sira, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 1, 0, ?, ?, ?)')
                ->execute([$a, $g === '' ? null : $g, $cv['id'], $depo, $userId, $simdi, $simdi]);
        } else {
            $pdo->prepare('INSERT INTO puantaj_giris_kisiler (ad, gorev, depo, aktif, sira, created_by, created_at, updated_at) VALUES (?, ?, ?, 1, 0, ?, ?, ?)')
                ->execute([$a, $g === '' ? null : $g, $depo, $userId, $simdi, $simdi]);
        }
        $id = (int)$pdo->lastInsertId();
        return ['ok' => true, 'hata' => null, 'id' => $id, 'audit' => ['create', $id, null, ['aktif' => 1, 'depo' => $depo, 'ad_uzunluk' => mb_strlen($a, 'UTF-8'), 'gorev_uzunluk' => mb_strlen($g, 'UTF-8')] + ($cv['id'] !== null ? ['cavus_id' => $cv['id']] : [])]];
    } catch (Throwable $e) {
        error_log('[puantaj_giris] kisi_ekle: ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Kişi kaydedilemedi.', 'id' => 0, 'audit' => null];
    }
}

/**
 * Ad/görev (+ v562 bağlı çavuş) düzenle. $cavus: false = çavuş alanı gönderilmedi (değişmez); null/''/0 = bağı kaldır; sayı = o çavuş.
 * Dönüş: ['ok','hata','islem'=>'update'|'degismedi','audit'].
 */
function pg_kisi_duzenle(PDO $pdo, array $kisi, string $ad, string $gorev, $cavus = false): array
{
    $a = pg_ad_dogrula($ad); $g = pg_gorev_dogrula($gorev);
    $kolonVar = pg_cavus_kolon_var($pdo);
    $cavusId = $kisi['cavus_id'] ?? null;
    $cavusDegisti = false;
    if ($cavus !== false) {
        $cv = pg_cavus_dogrula($pdo, $cavus, $cavusId);
        if (!$cv['ok']) return ['ok' => false, 'hata' => (string)$cv['hata'], 'islem' => '', 'audit' => null];
        if (!$kolonVar && $cv['id'] !== null) return ['ok' => false, 'hata' => 'Bağlı çavuş için migrate.php › Puantaj Girişi kartının çalıştırılması gerekiyor.', 'islem' => '', 'audit' => null];
        $cavusDegisti = $kolonVar && $cv['id'] !== $cavusId;
        if ($cavusDegisti) $cavusId = $cv['id'];
    }
    if ($a === null) return ['ok' => false, 'hata' => 'Ad 1 ile ' . PG_AD_MAX . ' karakter arasında olmalıdır.', 'islem' => '', 'audit' => null];
    if ($g === null) return ['ok' => false, 'hata' => 'Görev en çok ' . PG_GOREV_MAX . ' karakter olabilir.', 'islem' => '', 'audit' => null];
    if ($a === $kisi['ad'] && $g === $kisi['gorev'] && !$cavusDegisti) return ['ok' => true, 'hata' => null, 'islem' => 'degismedi', 'audit' => null];
    try {
        if ($cavusDegisti) {
            $pdo->prepare('UPDATE puantaj_giris_kisiler SET ad = ?, gorev = ?, cavus_id = ?, updated_at = ? WHERE id = ?')
                ->execute([$a, $g === '' ? null : $g, $cavusId, date('Y-m-d H:i:s'), (int)$kisi['id']]);
        } else {
            $pdo->prepare('UPDATE puantaj_giris_kisiler SET ad = ?, gorev = ?, updated_at = ? WHERE id = ?')
                ->execute([$a, $g === '' ? null : $g, date('Y-m-d H:i:s'), (int)$kisi['id']]);
        }
        $eski = ['ad_uzunluk' => mb_strlen($kisi['ad'], 'UTF-8'), 'gorev_uzunluk' => mb_strlen($kisi['gorev'], 'UTF-8')];
        $yeni = ['ad_uzunluk' => mb_strlen($a, 'UTF-8'), 'gorev_uzunluk' => mb_strlen($g, 'UTF-8'), 'ad_degisti' => $a !== $kisi['ad']];
        if ($cavusDegisti) { $eski['cavus_id'] = $kisi['cavus_id'] ?? null; $yeni['cavus_id'] = $cavusId; }
        return ['ok' => true, 'hata' => null, 'islem' => 'update', 'audit' => ['update', (int)$kisi['id'], $eski, $yeni]];
    } catch (Throwable $e) {
        error_log('[puantaj_giris] kisi_duzenle: ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Kişi kaydedilemedi.', 'islem' => '', 'audit' => null];
    }
}

/** Aktif/pasif. Kayıtlar (gün işaretleri) SİLİNMEZ. */
function pg_kisi_durum(PDO $pdo, array $kisi, bool $aktif): array
{
    if ((int)$kisi['aktif'] === ($aktif ? 1 : 0)) return ['ok' => true, 'hata' => null, 'islem' => 'degismedi', 'audit' => null];
    try {
        $pdo->prepare('UPDATE puantaj_giris_kisiler SET aktif = ?, updated_at = ? WHERE id = ?')->execute([$aktif ? 1 : 0, date('Y-m-d H:i:s'), (int)$kisi['id']]);
        return ['ok' => true, 'hata' => null, 'islem' => 'update', 'audit' => ['update', (int)$kisi['id'], ['aktif' => (int)$kisi['aktif']], ['aktif' => $aktif ? 1 : 0]]];
    } catch (Throwable $e) {
        error_log('[puantaj_giris] kisi_durum: ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Durum değiştirilemedi.', 'islem' => '', 'audit' => null];
    }
}

/**
 * Bir kişinin bir günü: $kod '' = temizle. Doğrulama (kod/tarih) burada da yapılır.
 * Dönüş: ['ok','islem'=>'create'|'update'|'delete'|'degismedi'|'yok','hata','kod'=>son kod,'audit'=>?[action,id,eski,yeni]].
 */
function pg_gun_uygula(PDO $pdo, int $kisiId, string $tarih, string $kod, ?int $userId, ?string $bugun = null): array
{
    $t = pi_tarih_dogrula($tarih, $bugun);
    if ($t === null) return ['ok' => false, 'islem' => '', 'hata' => 'Geçersiz tarih.', 'kod' => '', 'audit' => null];
    if ($kod !== '' && !pg_kod_gecerli($kod)) return ['ok' => false, 'islem' => '', 'hata' => 'Geçersiz işaret kodu.', 'kod' => '', 'audit' => null];
    $simdi = date('Y-m-d H:i:s');
    $oku = static function () use ($pdo, $kisiId, $t): ?array {
        $st = $pdo->prepare('SELECT id, kod FROM puantaj_giris_gunler WHERE kisi_id = ? AND tarih = ?');
        $st->execute([$kisiId, $t]);
        $r = $st->fetch();
        return $r ?: null;
    };
    try {
        $mevcut = $oku();
        $eski = $mevcut ? ['kisi_id' => $kisiId, 'tarih' => $t, 'kod' => (string)$mevcut['kod']] : null;
        if ($kod === '') {
            if (!$mevcut) return ['ok' => true, 'islem' => 'yok', 'hata' => null, 'kod' => '', 'audit' => null];
            $pdo->prepare('DELETE FROM puantaj_giris_gunler WHERE id = ?')->execute([(int)$mevcut['id']]);
            return ['ok' => true, 'islem' => 'delete', 'hata' => null, 'kod' => '', 'audit' => ['delete', (int)$mevcut['id'], $eski, null]];
        }
        $yeni = ['kisi_id' => $kisiId, 'tarih' => $t, 'kod' => $kod];
        if ($mevcut) {
            if ((string)$mevcut['kod'] === $kod) return ['ok' => true, 'islem' => 'degismedi', 'hata' => null, 'kod' => $kod, 'audit' => null];
            $pdo->prepare('UPDATE puantaj_giris_gunler SET kod = ?, user_id = ?, updated_at = ? WHERE id = ?')->execute([$kod, $userId, $simdi, (int)$mevcut['id']]);
            return ['ok' => true, 'islem' => 'update', 'hata' => null, 'kod' => $kod, 'audit' => ['update', (int)$mevcut['id'], $eski, $yeni]];
        }
        try {
            $pdo->prepare('INSERT INTO puantaj_giris_gunler (kisi_id, tarih, kod, user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$kisiId, $t, $kod, $userId, $simdi, $simdi]);
            return ['ok' => true, 'islem' => 'create', 'hata' => null, 'kod' => $kod, 'audit' => ['create', (int)$pdo->lastInsertId(), null, $yeni]];
        } catch (PDOException $e) {
            // Aynı anda başka istek aynı güne yazdı (UNIQUE): mevcut satırı güncelle.
            $m2 = $oku();
            if (!$m2) throw $e;
            $pdo->prepare('UPDATE puantaj_giris_gunler SET kod = ?, user_id = ?, updated_at = ? WHERE id = ?')->execute([$kod, $userId, $simdi, (int)$m2['id']]);
            return ['ok' => true, 'islem' => 'update', 'hata' => null, 'kod' => $kod, 'audit' => ['update', (int)$m2['id'], ['kisi_id' => $kisiId, 'tarih' => $t, 'kod' => (string)$m2['kod']], $yeni]];
        }
    } catch (Throwable $e) {
        error_log('[puantaj_giris] gun_uygula: ' . $e->getMessage());
        return ['ok' => false, 'islem' => '', 'hata' => 'İşaret kaydedilemedi.', 'kod' => '', 'audit' => null];
    }
}

/**
 * Kişi bazlı toplu işlem. mod: 'haftaici_x' (yalnız BOŞ hafta içi günlere X; hafta sonu atlanır) ya da 'ay_temizle'.
 * Dönüş: ['ok','hata','adet','degisim'=>[['k'=>kisi,'t'=>tarih,'c'=>kod],…]]. Tek transaction.
 */
function pg_toplu_uygula(PDO $pdo, int $kisiId, string $ay, string $mod, ?int $userId, ?string $bugun = null): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay) || pc_ay_dogrula($ay, '') !== $ay) return ['ok' => false, 'hata' => 'Geçersiz ay.', 'adet' => 0, 'degisim' => []];
    if (!in_array($mod, ['haftaici_x', 'ay_temizle'], true)) return ['ok' => false, 'hata' => 'Geçersiz işlem.', 'adet' => 0, 'degisim' => []];
    $bas = $ay . '-01'; $son = pc_ay_gez($ay, 1) . '-01';
    $degisim = [];
    $tx = !$pdo->inTransaction();
    try {
        if ($tx) $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT tarih FROM puantaj_giris_gunler WHERE kisi_id = ? AND tarih >= ? AND tarih < ?');
        $st->execute([$kisiId, $bas, $son]);
        $var = [];
        foreach ($st->fetchAll() as $r) $var[substr((string)$r['tarih'], 0, 10)] = true;
        if ($mod === 'ay_temizle') {
            $pdo->prepare('DELETE FROM puantaj_giris_gunler WHERE kisi_id = ? AND tarih >= ? AND tarih < ?')->execute([$kisiId, $bas, $son]);
            foreach (array_keys($var) as $t) $degisim[] = ['k' => $kisiId, 't' => $t, 'c' => ''];
        } else {
            $ins = $pdo->prepare('INSERT INTO puantaj_giris_gunler (kisi_id, tarih, kod, user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            $simdi = date('Y-m-d H:i:s');
            foreach (pc_gunler($ay) as $g) {
                if ($g['hs'] || isset($var[$g['tarih']]) || pi_tarih_dogrula($g['tarih'], $bugun) === null) continue;
                $ins->execute([$kisiId, $g['tarih'], 'X', $userId, $simdi, $simdi]);
                $degisim[] = ['k' => $kisiId, 't' => $g['tarih'], 'c' => 'X'];
            }
        }
        if ($tx) $pdo->commit();
        return ['ok' => true, 'hata' => null, 'adet' => count($degisim), 'degisim' => $degisim];
    } catch (Throwable $e) {
        if ($tx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[puantaj_giris] toplu: ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Toplu işlem yapılamadı.', 'adet' => 0, 'degisim' => []];
    }
}

/**
 * Bir güne, verilen kişilerin hepsi için tek kod ($kod '' = temizle). Tek transaction.
 * Dönüş: ['ok','hata','adet' (gerçekten değişen),'degisim'=>[['k','t','c'],…]].
 */
function pg_sutun_uygula(PDO $pdo, string $tarih, array $kisiIdleri, string $kod, ?int $userId, ?string $bugun = null): array
{
    if (pi_tarih_dogrula($tarih, $bugun) === null) return ['ok' => false, 'hata' => 'Geçersiz tarih.', 'adet' => 0, 'degisim' => []];
    if ($kod !== '' && !pg_kod_gecerli($kod)) return ['ok' => false, 'hata' => 'Geçersiz işaret kodu.', 'adet' => 0, 'degisim' => []];
    $degisim = [];
    $tx = !$pdo->inTransaction();
    try {
        if ($tx) $pdo->beginTransaction();
        foreach ($kisiIdleri as $kid) {
            $s = pg_gun_uygula($pdo, (int)$kid, $tarih, $kod, $userId, $bugun);
            if (!$s['ok']) throw new RuntimeException((string)$s['hata']);
            if (in_array($s['islem'], ['create', 'update', 'delete'], true)) $degisim[] = ['k' => (int)$kid, 't' => $tarih, 'c' => $s['kod']];
        }
        if ($tx) $pdo->commit();
        return ['ok' => true, 'hata' => null, 'adet' => count($degisim), 'degisim' => $degisim];
    } catch (Throwable $e) {
        if ($tx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[puantaj_giris] sutun: ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Toplu işlem yapılamadı.', 'adet' => 0, 'degisim' => []];
    }
}

// ── v555: Kişi yönetimi (liste · kalıcı silme · toplu işlem) ───────────────

const PG_TOPLU_ID_MAX = 500;      // tek istekte en çok kişi (kisi_toplu, gun_toplu_sec)
const PG_SEC_GUN_MAX = 31;        // gun_toplu_sec: en çok gün
const PG_SEC_HUCRE_MAX = 15500;   // gun_toplu_sec: kişi × gün üst sınırı

/**
 * Kişiler ekranı için liste: depo kuralı (depo_sql_in) + pasifler DAHİL, ad A-Z (sira yok sayılır),
 * her satırda kişinin TÜM aylardaki gün kaydı sayısı (gun). Hata fırlatabilir.
 * @return list<array{id:int,ad:string,gorev:string,depo:string,aktif:int,sira:int,gun:int}>
 */
function pg_kisiler_yonetim(PDO $pdo): array
{
    $kisiler = pg_kisiler_oku($pdo, true);
    $say = [];
    foreach ($pdo->query('SELECT kisi_id, COUNT(*) AS n FROM puantaj_giris_gunler GROUP BY kisi_id')->fetchAll() as $r) $say[(int)$r['kisi_id']] = (int)$r['n'];
    foreach ($kisiler as &$k) $k['gun'] = $say[$k['id']] ?? 0;
    unset($k);
    usort($kisiler, static fn($a, $b) => strcmp(pc_fold($a['ad']), pc_fold($b['ad'])) ?: $a['id'] <=> $b['id']);
    return $kisiler;
}

/** Ad/görev araması (Türkçe duyarsız) + durum süzgeci ('hepsi' | 'aktif' | 'pasif'). Sıra korunur. */
function pg_kisiler_filtrele(array $kisiler, string $q, string $durum, string $cavus = ''): array
{
    $kisiler = pg_cavus_suz($kisiler, pg_cavus_suz_dogrula($cavus));
    if ($durum === 'aktif') $kisiler = array_values(array_filter($kisiler, static fn($k) => (int)$k['aktif'] === 1));
    elseif ($durum === 'pasif') $kisiler = array_values(array_filter($kisiler, static fn($k) => (int)$k['aktif'] === 0));
    $adaylar = [];
    foreach ($kisiler as $i => $k) $adaylar[] = ['i' => $i, 'ad' => $k['ad'], 'alt' => $k['gorev'], 'cavus' => (string)($k['cavus'] ?? '')];
    return array_map(static fn($r) => $kisiler[$r['i']], pc_satir_filtre($adaylar, $q));
}

/** Toplam / aktif / pasif sayaçları. */
function pg_kisi_sayaclari(array $kisiler): array
{
    $aktif = 0;
    foreach ($kisiler as $k) if ((int)$k['aktif'] === 1) $aktif++;
    return ['toplam' => count($kisiler), 'aktif' => $aktif, 'pasif' => count($kisiler) - $aktif];
}

/** Kimliklere göre kişiler: [id => satır]. Depo süzgeci YOK (çağıran depot_visible_to_user ile denetler). Bulunamayan id sonuçta yoktur. */
function pg_kisiler_idlerle(PDO $pdo, array $idler): array
{
    $out = [];
    foreach (array_chunk(array_values($idler), 200) as $parca) {
        $st = $pdo->prepare('SELECT ' . pg_kisi_kolonlar($pdo) . ' FROM puantaj_giris_kisiler WHERE id IN (' . implode(',', array_fill(0, count($parca), '?')) . ')');
        $st->execute($parca);
        foreach ($st->fetchAll() as $r) $out[(int)$r['id']] = pg_kisi_satir($r);
    }
    return $out === [] ? $out : array_column(pg_cavus_adlari_ekle($pdo, array_values($out)), null, 'id');
}

/**
 * Kişiyi KALICI siler: önce gün kayıtları, sonra kişi — TEK transaction (geri alınamaz).
 * Dönüş: ['ok','hata','gun_silinen','audit'=>[action,id,eski,yeni]]. Audit değerlerinde ad/görev YOK.
 */
function pg_kisi_sil(PDO $pdo, array $kisi): array
{
    $id = (int)$kisi['id'];
    $tx = !$pdo->inTransaction();
    try {
        if ($tx) $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT COUNT(*) FROM puantaj_giris_gunler WHERE kisi_id = ?');
        $st->execute([$id]);
        $gun = (int)$st->fetchColumn();
        $pdo->prepare('DELETE FROM puantaj_giris_gunler WHERE kisi_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM puantaj_giris_kisiler WHERE id = ?')->execute([$id]);
        if ($tx) $pdo->commit();
        return ['ok' => true, 'hata' => null, 'gun_silinen' => $gun, 'audit' => ['delete', $id, ['aktif' => (int)$kisi['aktif'], 'depo' => (string)$kisi['depo'], 'gun_kaydi' => $gun], null]];
    } catch (Throwable $e) {
        if ($tx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[puantaj_giris] kisi_sil: ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Kişi silinemedi.', 'gun_silinen' => 0, 'audit' => null];
    }
}

/**
 * v562: seçili kişileri TEK çavuşa bağlar (ya da $cavus null/''/0 ile bağı kaldırır). $kisiler: [id => satır] (çağıran doğruladı).
 * Pasif kişiler de güncellenir. TEK transaction. Dönüş: ['ok','hata','etkilenen' (gerçekten değişen),'atlanan' (zaten o çavuşta),'cavus_id'].
 */
function pg_kisi_cavus_toplu(PDO $pdo, array $kisiler, $cavus): array
{
    $bos = static fn(string $h): array => ['ok' => false, 'hata' => $h, 'etkilenen' => 0, 'atlanan' => 0, 'cavus_id' => null];
    if (!pg_cavus_kolon_var($pdo)) return $bos('Bağlı çavuş için migrate.php › Puantaj Girişi kartının çalıştırılması gerekiyor.');
    $cv = pg_cavus_dogrula($pdo, $cavus);
    if (!$cv['ok']) return $bos((string)$cv['hata']);
    $etkilenen = 0; $atlanan = 0;
    $tx = !$pdo->inTransaction();
    try {
        if ($tx) $pdo->beginTransaction();
        $simdi = date('Y-m-d H:i:s');
        $guncelle = $pdo->prepare('UPDATE puantaj_giris_kisiler SET cavus_id = ?, updated_at = ? WHERE id = ?');
        foreach ($kisiler as $k) {
            if (($k['cavus_id'] ?? null) === $cv['id']) { $atlanan++; continue; }
            $guncelle->execute([$cv['id'], $simdi, (int)$k['id']]);
            $etkilenen++;
        }
        if ($tx) $pdo->commit();
        return ['ok' => true, 'hata' => null, 'etkilenen' => $etkilenen, 'atlanan' => $atlanan, 'cavus_id' => $cv['id']];
    } catch (Throwable $e) {
        if ($tx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[puantaj_giris] kisi_cavus_toplu: ' . $e->getMessage());
        return $bos('Çavuş bağlanamadı.');
    }
}

/**
 * Toplu kişi işlemi: 'pasif' | 'aktif' | 'sil'. $kisiler: [id => satır] (çağıran doğruladı). TEK transaction, hepsi ya da hiçbiri.
 * Dönüş: ['ok','hata','etkilenen' (gerçekten değişen/silinen),'atlanan' (zaten o durumda),'gun_silinen'].
 */
function pg_kisi_toplu(PDO $pdo, array $kisiler, string $islem): array
{
    if (!in_array($islem, ['pasif', 'aktif', 'sil'], true)) return ['ok' => false, 'hata' => 'Geçersiz işlem.', 'etkilenen' => 0, 'atlanan' => 0, 'gun_silinen' => 0];
    $etkilenen = 0; $atlanan = 0; $gunSilinen = 0;
    $tx = !$pdo->inTransaction();
    try {
        if ($tx) $pdo->beginTransaction();
        $simdi = date('Y-m-d H:i:s');
        $sayStmt = $pdo->prepare('SELECT COUNT(*) FROM puantaj_giris_gunler WHERE kisi_id = ?');
        $gunSil = $pdo->prepare('DELETE FROM puantaj_giris_gunler WHERE kisi_id = ?');
        $kisiSil = $pdo->prepare('DELETE FROM puantaj_giris_kisiler WHERE id = ?');
        $durum = $pdo->prepare('UPDATE puantaj_giris_kisiler SET aktif = ?, updated_at = ? WHERE id = ?');
        foreach ($kisiler as $k) {
            $id = (int)$k['id'];
            if ($islem === 'sil') {
                $sayStmt->execute([$id]);
                $gunSilinen += (int)$sayStmt->fetchColumn();
                $gunSil->execute([$id]);
                $kisiSil->execute([$id]);
                $etkilenen++;
                continue;
            }
            $hedef = $islem === 'aktif' ? 1 : 0;
            if ((int)$k['aktif'] === $hedef) { $atlanan++; continue; }
            $durum->execute([$hedef, $simdi, $id]);
            $etkilenen++;
        }
        if ($tx) $pdo->commit();
        return ['ok' => true, 'hata' => null, 'etkilenen' => $etkilenen, 'atlanan' => $atlanan, 'gun_silinen' => $gunSilinen];
    } catch (Throwable $e) {
        if ($tx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[puantaj_giris] kisi_toplu: ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Toplu işlem yapılamadı.', 'etkilenen' => 0, 'atlanan' => 0, 'gun_silinen' => 0];
    }
}

/**
 * ÇOK KİŞİ × ÇOK GÜN toplu işaret. $kisiler: [id => satır] (çağıran: var + depo kuralı doğruladı). $tarihler: seçili aya ait günler.
 * $kod '' = temizle (ustune_yaz'dan bağımsız seçili hücreleri siler). Dolu hücre: $ustuneYaz kapalıysa DOKUNULMAZ (atlanan), açıksa yazılır.
 * PASİF kişiler atlanır (pasif_atlanan). TEK transaction. Sınırlar: kişi ≤ PG_TOPLU_ID_MAX, gün ≤ PG_SEC_GUN_MAX, kişi×gün ≤ PG_SEC_HUCRE_MAX.
 * Dönüş: ['ok','hata','kisi_sayisi' (işlenen aktif),'gun_sayisi','yazilan' (yeni + güncellenen + silinen),'atlanan','degismedi','pasif_atlanan','degisim'=>[['k','t','c'],…]].
 */
function pg_gun_toplu_sec(PDO $pdo, string $ay, array $kisiler, array $tarihler, string $kod, bool $ustuneYaz, ?int $userId, ?string $bugun = null): array
{
    $hata = static fn(string $m): array => ['ok' => false, 'hata' => $m, 'kisi_sayisi' => 0, 'gun_sayisi' => 0, 'yazilan' => 0, 'atlanan' => 0, 'degismedi' => 0, 'pasif_atlanan' => 0, 'degisim' => []];
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay) || pc_ay_dogrula($ay, '') !== $ay) return $hata('Geçersiz ay.');
    if ($kod !== '' && !pg_kod_gecerli($kod)) return $hata('Geçersiz işaret kodu.');
    $gunler = [];
    foreach ($tarihler as $t) {
        if (!is_string($t) || strncmp($t, $ay . '-', 8) !== 0 || pi_tarih_dogrula($t, $bugun) === null) return $hata('Seçilen günlerden biri geçersiz ya da seçili aya ait değil.');
        $gunler[$t] = true;
    }
    $gunler = array_keys($gunler);
    sort($gunler);
    if ($gunler === []) return $hata('En az bir gün seçin.');
    if (count($gunler) > PG_SEC_GUN_MAX) return $hata('En çok ' . PG_SEC_GUN_MAX . ' gün seçilebilir.');
    if ($kisiler === []) return $hata('En az bir kişi seçin.');
    if (count($kisiler) > PG_TOPLU_ID_MAX) return $hata('En çok ' . PG_TOPLU_ID_MAX . ' kişi seçilebilir.');
    $aktifler = array_values(array_filter($kisiler, static fn($k) => (int)$k['aktif'] === 1));
    $pasifSayi = count($kisiler) - count($aktifler);
    if ($aktifler === []) return $hata('Seçilen kişilerin hepsi pasif; işaret girilemez.');
    if (count($aktifler) * count($gunler) > PG_SEC_HUCRE_MAX) return $hata('Seçim çok büyük (en çok ' . PG_SEC_HUCRE_MAX . ' hücre).');

    $degisim = []; $yazilan = 0; $atlanan = 0; $degismedi = 0;
    $tx = !$pdo->inTransaction();
    try {
        if ($tx) $pdo->beginTransaction();
        $mevcut = [];   // [kisi_id][tarih] = kod
        $yer = implode(',', array_fill(0, count($gunler), '?'));
        foreach (array_chunk(array_map(static fn($k) => (int)$k['id'], $aktifler), 200) as $parca) {
            $st = $pdo->prepare('SELECT kisi_id, tarih, kod FROM puantaj_giris_gunler WHERE kisi_id IN (' . implode(',', array_fill(0, count($parca), '?')) . ') AND tarih IN (' . $yer . ')');
            $st->execute(array_merge($parca, $gunler));
            foreach ($st->fetchAll() as $r) $mevcut[(int)$r['kisi_id']][substr((string)$r['tarih'], 0, 10)] = (string)$r['kod'];
        }
        $simdi = date('Y-m-d H:i:s');
        $ekle = $pdo->prepare('INSERT INTO puantaj_giris_gunler (kisi_id, tarih, kod, user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $guncelle = $pdo->prepare('UPDATE puantaj_giris_gunler SET kod = ?, user_id = ?, updated_at = ? WHERE kisi_id = ? AND tarih = ?');
        $sil = $pdo->prepare('DELETE FROM puantaj_giris_gunler WHERE kisi_id = ? AND tarih = ?');
        foreach ($aktifler as $k) {
            $kid = (int)$k['id'];
            foreach ($gunler as $t) {
                $var = $mevcut[$kid][$t] ?? null;
                if ($kod === '') {
                    if ($var === null) { $degismedi++; continue; }
                    $sil->execute([$kid, $t]);
                    $yazilan++; $degisim[] = ['k' => $kid, 't' => $t, 'c' => ''];
                } elseif ($var === null) {
                    $ekle->execute([$kid, $t, $kod, $userId, $simdi, $simdi]);
                    $yazilan++; $degisim[] = ['k' => $kid, 't' => $t, 'c' => $kod];
                } elseif ($var === $kod) {
                    $degismedi++;
                } elseif ($ustuneYaz) {
                    $guncelle->execute([$kod, $userId, $simdi, $kid, $t]);
                    $yazilan++; $degisim[] = ['k' => $kid, 't' => $t, 'c' => $kod];
                } else {
                    $atlanan++;
                }
            }
        }
        if ($tx) $pdo->commit();
        return ['ok' => true, 'hata' => null, 'kisi_sayisi' => count($aktifler), 'gun_sayisi' => count($gunler), 'yazilan' => $yazilan, 'atlanan' => $atlanan, 'degismedi' => $degismedi, 'pasif_atlanan' => $pasifSayi, 'degisim' => $degisim];
    } catch (Throwable $e) {
        if ($tx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[puantaj_giris] gun_toplu_sec: ' . $e->getMessage());
        return $hata('Toplu işlem yapılamadı.');
    }
}
