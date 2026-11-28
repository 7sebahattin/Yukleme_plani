<?php
// =========================================================
// config/cari_ek_sema.php — Cari ek şeması (v611): cari tipleri tanımlardan · TCKN / doğum tarihi · hızlı ekleme bayrağı
// cari_core.php sonunda yüklenir (include-only; yalnız fonksiyon bildirir).
//
// Şema YALNIZ migrate.php "Cari — Tipler · TCKN/Doğum tarihi · Hızlı ekleme" kartından (cari_ek_migrate) kurulur.
// Sayfalar/çekirdek açılışta ALTER ATMAZ; cari_tablolar() DDL'inde ALTER/FK yok (yeni kurulum için kolonlar CREATE'te hazırdır).
// Kolonlar yokken (cari_ek_hazir() false) her şey eskisi gibi çalışır: yeni alanlar gizli, kayıt onları yazmaz.
// Emsal: config/siparis_sofor.php (siparis_sofor_hazir / _migrate).
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/**
 * Sistem cari tipleri: kod → [ad, sira, pasif yapılabilir mi]. Kod DEĞİŞMEZ; ad değiştirilebilir; silinemez.
 * @return array<string,array{0:string,1:int,2:bool}>
 */
function cari_ek_sistem_tipleri(): array
{
    return [
        'musteri'   => ['Müşteri', 10, false],
        'tedarikci' => ['Tedarikçi', 20, false],
        'ikisi'     => ['Müşteri+Tedarikçi', 30, true],
        'personel'  => ['Personel/Çavuş', 40, true],
        'diger'     => ['Diğer', 50, true],
        'mustahsil' => ['Müstahsil', 60, true],
        'nakliyeci' => ['Nakliyeci', 70, true],
        'gumrukcu'  => ['Gümrükçü', 80, true],
    ];
}

/** Bu kod sistem tipi mi? */
function cari_ek_sistem_tip_mi(?string $kod): bool
{
    return $kod !== null && $kod !== '' && isset(cari_ek_sistem_tipleri()[$kod]);
}

/** Kolon var mı (SELECT ... LIMIT 0 yoklaması). */
function cari_ek_kolon_var(PDO $pdo, string $tablo, string $kolon): bool
{
    try { $pdo->query("SELECT `$kolon` FROM `$tablo` LIMIT 0"); return true; } catch (PDOException $e) { return false; }
}

/** İndeks var mı (MySQL: SHOW INDEX · SQLite: PRAGMA index_list). */
function cari_ek_indeks_var(PDO $pdo, string $tablo, string $indeks): bool
{
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($pdo->query("PRAGMA index_list(`$tablo`)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((string)($r['name'] ?? '') === $indeks) return true;
            }
            return false;
        }
        $st = $pdo->prepare("SHOW INDEX FROM `$tablo` WHERE Key_name = ?");
        $st->execute([$indeks]);
        return $st->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (PDOException $e) {
        return false;
    }
}

/** Yeni kolonların hepsi var mı? (yalnız olumlu sonuç önbelleğe alınır → migrasyondan sonra sayfa hemen görür) */
function cari_ek_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        $pdo->query('SELECT kod FROM cari_tanimlar LIMIT 0');
        $pdo->query('SELECT tckn, dogum_tarihi, hizli FROM cari_kartlar LIMIT 0');
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Sistem tiplerini ekler; eksik olanı tamamlar, var olana dokunmaz (idempotent). @return int eklenen sayısı */
function cari_ek_tip_tohumla(PDO $pdo): int
{
    $eklenen = 0;
    $zaman = date('Y-m-d H:i:s');
    foreach (cari_ek_sistem_tipleri() as $kod => [$ad, $sira]) {
        $st = $pdo->prepare("SELECT id FROM cari_tanimlar WHERE tur = 'tip' AND kod = ?");
        $st->execute([$kod]);
        if ($st->fetchColumn() !== false) continue;
        // Aynı adlı kodsuz satır (elle girilmiş) varsa onu sistem tipine bağla; yoksa ekle
        $st = $pdo->prepare("SELECT id FROM cari_tanimlar WHERE tur = 'tip' AND ad = ? AND (kod IS NULL OR kod = '')");
        $st->execute([$ad]);
        $var = $st->fetchColumn();
        if ($var !== false) {
            $pdo->prepare('UPDATE cari_tanimlar SET kod = ? WHERE id = ?')->execute([$kod, (int)$var]);
            continue;
        }
        // Ad başka bir satırda dolu kodla duruyorsa (UNIQUE tur+ad) eşsiz bir ad üret
        $aday = $ad; $n = 1;
        $sor = $pdo->prepare("SELECT 1 FROM cari_tanimlar WHERE tur = 'tip' AND ad = ?");
        while (true) {
            $sor->execute([$aday]);
            if ($sor->fetchColumn() === false) break;
            $aday = $ad . ' (' . (++$n) . ')';
        }
        $pdo->prepare("INSERT INTO cari_tanimlar (tur, ad, kod, sira, aktif, created_at) VALUES ('tip', ?, ?, ?, 1, ?)")
            ->execute([$aday, $kod, $sira, $zaman]);
        $eklenen++;
    }
    cari_tipler_onbellek_sifirla();
    return $eklenen;
}

/**
 * Cari ek şemasını kurar (idempotent; her adım bir sonuç satırı). Yalnız migrate.php kartından çağrılır.
 * @return list<array{adim:string,durum:string,mesaj:string}> durum: var|eklendi|kaldirildi|hata
 */
function cari_ek_migrate(PDO $pdo): array
{
    $rapor = [];
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if (!cari_hazir($pdo)) {
        return [['adim' => 'cari tabloları', 'durum' => 'hata', 'mesaj' => 'Önce "Cari İşlemler" kartından tabloları oluşturun.']];
    }
    $calistir = static function (string $adim, string $sql, string $okMesaj) use ($pdo, &$rapor): void {
        try {
            $pdo->exec($sql);
            $rapor[] = ['adim' => $adim, 'durum' => 'eklendi', 'mesaj' => $okMesaj];
        } catch (PDOException $e) {
            error_log('[cari_ek_migrate] ' . $adim . ': ' . $e->getMessage());
            $rapor[] = ['adim' => $adim, 'durum' => 'hata', 'mesaj' => 'Adım uygulanamadı (ayrıntı sunucu günlüğünde).'];
        }
    };
    $indeksEkle = static function (string $tablo, string $ad, string $kolonlar) use ($pdo, $sqlite, $calistir, &$rapor): void {
        $adim = "$tablo.$ad (indeks)";
        if (cari_ek_indeks_var($pdo, $tablo, $ad)) { $rapor[] = ['adim' => $adim, 'durum' => 'var', 'mesaj' => 'İndeks zaten mevcut.']; return; }
        $calistir($adim, $sqlite ? "CREATE INDEX `$ad` ON `$tablo` ($kolonlar)" : "ALTER TABLE `$tablo` ADD INDEX `$ad` ($kolonlar)", 'İndeks eklendi.');
    };
    $kolonEkle = static function (string $tablo, string $kolon, string $tanim) use ($pdo, $calistir, &$rapor): void {
        $adim = "$tablo.$kolon";
        if (cari_ek_kolon_var($pdo, $tablo, $kolon)) { $rapor[] = ['adim' => $adim, 'durum' => 'var', 'mesaj' => 'Kolon zaten mevcut.']; return; }
        $calistir($adim, "ALTER TABLE `$tablo` ADD COLUMN `$kolon` $tanim", 'Kolon eklendi.');
    };

    $kolonEkle('cari_tanimlar', 'kod', 'VARCHAR(20) NULL DEFAULT NULL');
    $indeksEkle('cari_tanimlar', 'idx_carit_tur_kod', '`tur`, `kod`');
    $kolonEkle('cari_kartlar', 'tckn', 'CHAR(11) NULL DEFAULT NULL');
    $kolonEkle('cari_kartlar', 'dogum_tarihi', 'DATE NULL DEFAULT NULL');
    $kolonEkle('cari_kartlar', 'hizli', 'TINYINT(1) NOT NULL DEFAULT 0');
    $indeksEkle('cari_kartlar', 'idx_cari_tckn', '`tckn`');
    $indeksEkle('cari_kartlar', 'idx_cari_vergi', '`vergi_no`');

    // Eski "Tanımlar" bağı (md_id) kopar: indeks + kolon düşer (veri göçü yok)
    if (cari_ek_kolon_var($pdo, 'cari_kartlar', 'md_id')) {
        if (cari_ek_indeks_var($pdo, 'cari_kartlar', 'idx_cari_md')) {
            try { $pdo->exec($sqlite ? 'DROP INDEX `idx_cari_md`' : 'ALTER TABLE `cari_kartlar` DROP INDEX `idx_cari_md`'); } catch (PDOException $e) { error_log('[cari_ek_migrate] idx_cari_md: ' . $e->getMessage()); }
        }
        try {
            $pdo->exec('ALTER TABLE `cari_kartlar` DROP COLUMN `md_id`');
            $rapor[] = ['adim' => 'cari_kartlar.md_id', 'durum' => 'kaldirildi', 'mesaj' => 'Eski Tanımlar bağı (md_id) kaldırıldı.'];
        } catch (PDOException $e) {
            error_log('[cari_ek_migrate] md_id: ' . $e->getMessage());
            $rapor[] = ['adim' => 'cari_kartlar.md_id', 'durum' => 'hata', 'mesaj' => 'md_id kolonu kaldırılamadı (ayrıntı sunucu günlüğünde).'];
        }
    } else {
        $rapor[] = ['adim' => 'cari_kartlar.md_id', 'durum' => 'var', 'mesaj' => 'md_id zaten yok.'];
    }

    // Sistem cari tipleri (kod kolonu hazırsa)
    if (cari_ek_kolon_var($pdo, 'cari_tanimlar', 'kod')) {
        try {
            $n = cari_ek_tip_tohumla($pdo);
            $rapor[] = ['adim' => 'sistem cari tipleri', 'durum' => $n > 0 ? 'eklendi' : 'var', 'mesaj' => $n > 0 ? $n . ' sistem tipi eklendi.' : 'Sistem tipleri zaten tanımlı.'];
        } catch (PDOException $e) {
            error_log('[cari_ek_migrate] tip tohumu: ' . $e->getMessage());
            $rapor[] = ['adim' => 'sistem cari tipleri', 'durum' => 'hata', 'mesaj' => 'Tip tohumu yazılamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}

// ── Kimlik algoritmaları ────────────────────────────────────────────────────

/** T.C. kimlik no: 11 hane, ilk hane 0 değil, 10. ve 11. hane algoritması. */
function cari_tckn_gecerli(string $t): bool
{
    if (!preg_match('/^[1-9]\d{10}$/', $t)) return false;
    $d = array_map('intval', str_split($t));
    $tek = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
    $cift = $d[1] + $d[3] + $d[5] + $d[7];
    if ((($tek * 7 - $cift) % 10 + 10) % 10 !== $d[9]) return false;
    return (array_sum(array_slice($d, 0, 10)) % 10) === $d[10];
}

/** Vergi kimlik no (VKN): 10 hane + GİB sağlama basamağı algoritması. */
function cari_vkn_gecerli(string $v): bool
{
    if (!preg_match('/^\d{10}$/', $v)) return false;
    $d = array_map('intval', str_split($v));
    $top = 0;
    for ($i = 0; $i < 9; $i++) {
        $t = ($d[$i] + 9 - $i) % 10;
        $p = ($t * (2 ** (9 - $i))) % 9;
        if ($t !== 0 && $p === 0) $p = 9;
        $top += $p;
    }
    return ((10 - $top % 10) % 10) === $d[9];
}

/** TCKN'yi mesajlarda maskeler: ilk 2 + ******* + son 2. */
function cari_tckn_maske(string $t): string
{
    return strlen($t) === 11 ? substr($t, 0, 2) . '*******' . substr($t, -2) : '***';
}

/** Aynı VKN/TCKN taşıyan başka kart (pasif dahil). @return ?array{id:int,kod:string,unvan:string} */
function cari_kimlik_cakisma(PDO $pdo, string $alan, string $deger, ?int $haricId): ?array
{
    if (!in_array($alan, ['vergi_no', 'tckn'], true) || $deger === '') return null;
    $st = $pdo->prepare("SELECT id, kod, unvan FROM cari_kartlar WHERE $alan = ?" . ($haricId !== null ? ' AND id <> ?' : '') . ' ORDER BY id LIMIT 1');
    $st->execute($haricId !== null ? [$deger, $haricId] : [$deger]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : ['id' => (int)$r['id'], 'kod' => (string)$r['kod'], 'unvan' => (string)$r['unvan']];
}

// ── Eksik alan kuralı (TEK kural) ───────────────────────────────────────────

/**
 * Kartın tamamlanması gereken alanları (alan anahtarı → etiket). Boş = eksik yok.
 *  - Her tip: unvan, tip.
 *  - musteri / tedarikci / ikisi / nakliyeci / gumrukcu: vergi no VEYA TCKN + telefon VEYA adres.
 *  - mustahsil: telefon (TCKN / doğum tarihi zorunlu değil, eksik sayılmaz).
 * @return array<string,string>
 */
function cari_kart_eksik_alanlar(array $kart): array
{
    $dolu = static fn(string $a): bool => trim((string)($kart[$a] ?? '')) !== '';
    $e = [];
    if (!$dolu('unvan')) $e['unvan'] = 'Ünvan';
    $tip = trim((string)($kart['tip'] ?? ''));
    if ($tip === '') { $e['tip'] = 'Cari tipi'; return $e; }
    if (in_array($tip, ['musteri', 'tedarikci', 'ikisi', 'nakliyeci', 'gumrukcu'], true)) {
        if (!$dolu('vergi_no') && !$dolu('tckn')) $e['kimlik'] = 'Vergi no veya TCKN';
        if (!$dolu('telefon') && !$dolu('adres')) $e['iletisim'] = 'Telefon veya adres';
    } elseif ($tip === 'mustahsil') {
        if (!$dolu('telefon')) $e['telefon'] = 'Telefon';
    }
    return $e;
}

/** @return list<string> eksik alan etiketleri (cari_kart_eksik_alanlar'ın değer listesi). */
function cari_kart_eksikler(array $kart): array
{
    return array_values(cari_kart_eksik_alanlar($kart));
}
