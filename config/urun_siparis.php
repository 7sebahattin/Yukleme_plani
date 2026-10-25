<?php
// =========================================================
// config/urun_siparis.php — Ürün Siparişleri (firma + ürün + çoklu kalibre/palet, tek satırlık liste; v409)
//
// OGL "Siparişler" (siparis_*) modülünden BAĞIMSIZDIR: kendi tabloları, kendi uçları. Yetki kapısı yine
// can_siparis() (siparis.read / write / delete) — yeni yetki YOK.
// Şema yalnız migrate.php "Ürün siparişleri" kartından kurulur (usip_migrate); sayfalar tablo yokken "kurulmadı" der.
// Hesap TEK yerde (usip_hesap): kasa = toplam palet × palet kasa, kg = kasa × birim ağırlık, tutar = kg × birim fiyat.
// Sunucu her kayıtta yeniden hesaplar; istemci özeti yalnız önizlemedir.
// =========================================================
declare(strict_types=1);

const USIP_SAYFA_ADEDI    = 50;
const USIP_YAZDIR_LIMIT   = 500;
const USIP_KALIBRE_LIMIT  = 30;

/** @return array<string,string> tablo adı → CREATE */
function usip_tablolar(): array
{
    return [
        'urun_siparisleri' => "CREATE TABLE IF NOT EXISTS `urun_siparisleri` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `firma`           VARCHAR(150)  NOT NULL,
            `urun`            VARCHAR(150)  NOT NULL,
            `teslimat_tarihi` DATE          NOT NULL,
            `palet_cinsi`     VARCHAR(100)  NULL DEFAULT NULL,
            `kasa_cinsi`      VARCHAR(100)  NULL DEFAULT NULL,
            `palet_kasa`      INT           NOT NULL DEFAULT 0,
            `birim_agirlik`   DECIMAL(10,3) NOT NULL DEFAULT 0,
            `birim_fiyat`     DECIMAL(12,4) NULL DEFAULT NULL,
            `toplam_palet`    INT           NOT NULL DEFAULT 0,
            `toplam_kasa`     INT           NOT NULL DEFAULT 0,
            `toplam_kg`       DECIMAL(14,3) NOT NULL DEFAULT 0,
            `tutar`           DECIMAL(16,2) NOT NULL DEFAULT 0,
            `nakliyeci`       VARCHAR(150)  NULL DEFAULT NULL,
            `cekici_plaka`    VARCHAR(20)   NULL DEFAULT NULL,
            `dorse_plaka`     VARCHAR(20)   NULL DEFAULT NULL,
            `sofor_adi`       VARCHAR(150)  NULL DEFAULT NULL,
            `sofor_telefon`   VARCHAR(40)   NULL DEFAULT NULL,
            `yurtici_gumruk`  VARCHAR(150)  NULL DEFAULT NULL,
            `navlun_no`       VARCHAR(60)   NULL DEFAULT NULL,
            `siparis_no`      VARCHAR(60)   NULL DEFAULT NULL,
            `siparis_firma`   VARCHAR(150)  NULL DEFAULT NULL,
            `tasima_sekli`    VARCHAR(40)   NULL DEFAULT NULL,
            `lot_no`          VARCHAR(60)   NULL DEFAULT NULL,
            `teslim_yeri`     VARCHAR(150)  NULL DEFAULT NULL,
            `teslim_sekli`    VARCHAR(10)   NULL DEFAULT NULL,
            `aciklama`        VARCHAR(1000) NULL DEFAULT NULL,
            `depo`            VARCHAR(100)  NOT NULL DEFAULT '',
            `created_by`      INT           NULL DEFAULT NULL,
            `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_by`      INT           NULL DEFAULT NULL,
            `updated_at`      DATETIME      NULL DEFAULT NULL,
            `deleted_at`      DATETIME      NULL DEFAULT NULL,
            `deleted_by`      INT           NULL DEFAULT NULL,
            KEY `idx_usip_tarih` (`teslimat_tarihi`),
            KEY `idx_usip_depo` (`depo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
        'urun_siparis_kalibreler' => "CREATE TABLE IF NOT EXISTS `urun_siparis_kalibreler` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `siparis_id` INT         NOT NULL,
            `sira`       INT         NOT NULL DEFAULT 0,
            `kalibre`    VARCHAR(20) NOT NULL,
            `palet`      INT         NOT NULL DEFAULT 0,
            KEY `idx_usipk_siparis` (`siparis_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
    ];
}

function usip_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        $pdo->query('SELECT id FROM urun_siparisleri LIMIT 0');
        $pdo->query('SELECT id FROM urun_siparis_kalibreler LIMIT 0');
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** v427 ek alanlar (yalnız nullable ADD COLUMN): [kolon, tanım]. Eski kurulumlarda usip_migrate() ekler. */
function usip_ek_kolonlar(): array
{
    return [
        ['siparis_no', 'VARCHAR(60) NULL DEFAULT NULL'],
        ['siparis_firma', 'VARCHAR(150) NULL DEFAULT NULL'],
        ['tasima_sekli', 'VARCHAR(40) NULL DEFAULT NULL'],
        ['lot_no', 'VARCHAR(60) NULL DEFAULT NULL'],
        ['teslim_yeri', 'VARCHAR(150) NULL DEFAULT NULL', 'b'],
        ['teslim_sekli', 'VARCHAR(10) NULL DEFAULT NULL', 'b'],
    ];
}

/** Teslim şekli (Incoterms) kod önerileri; alan serbest koddur, liste yalnız öneridir. @return list<string> */
function usip_teslim_kodlari(): array
{
    return ['EXW', 'FCA', 'FAS', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];
}

/** Teslim şekli kodu: büyük harf, yalnız harf/rakam, en çok 10. */
function usip_kod(string $s): ?string
{
    $s = mb_substr((string)preg_replace('/[^A-Z0-9]/', '', tr_upper($s)), 0, 10, 'UTF-8');
    return $s === '' ? null : $s;
}

/** Taşıma şekli beyaz listesi (select seçenekleri; sunucu da yalnız bunları kabul eder). @return list<string> */
function usip_tasima_sekilleri(): array
{
    return ['Karayolu', 'Denizyolu', 'Havayolu', 'Demiryolu', 'Konteyner', 'Diğer'];
}

/** Ek alan kolonları kurulu mu? Değilse sayfa bu alanları gizler ve kayıt onları yazmaz. */
function usip_ek_hazir(PDO $pdo, string $grup = 'a'): bool
{
    static $var = [];
    $k = spl_object_id($pdo) . $grup;
    if (isset($var[$k])) return true;
    try {
        $kol = array_values(array_filter(usip_ek_kolonlar(), static fn($c) => ($c[2] ?? 'a') === $grup));
        $pdo->query('SELECT ' . implode(',', array_map(static fn($c) => '`' . $c[0] . '`', $kol)) . ' FROM urun_siparisleri LIMIT 0');
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> durum: var|olusturuldu|eklendi|hata */
function usip_migrate(PDO $pdo): array
{
    $rapor = [];
    foreach (usip_tablolar() as $ad => $ddl) {
        $var = false;
        try { $pdo->query("SELECT 1 FROM `$ad` LIMIT 0"); $var = true; } catch (PDOException $e) { $var = false; }
        if ($var) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            $pdo->exec($ddl);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
        } catch (PDOException $e) {
            error_log('[usip_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    // v427: eski kurulumda yeni alanlar yoksa tek tek ADD COLUMN (yalnız nullable, mevcut veriye dokunmaz)
    $anaVar = true;
    try { $pdo->query('SELECT 1 FROM urun_siparisleri LIMIT 0'); } catch (PDOException $e) { $anaVar = false; }
    if ($anaVar) {
        foreach (usip_ek_kolonlar() as [$kol, $tan]) {   // üçüncü öğe (grup) yok sayılır
            $ad = 'urun_siparisleri.' . $kol;
            try { $pdo->query("SELECT `$kol` FROM urun_siparisleri LIMIT 0"); $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Kolon zaten mevcut.']; continue; }
            catch (PDOException $e) { /* yok → ekle */ }
            try {
                $pdo->exec("ALTER TABLE urun_siparisleri ADD COLUMN `$kol` $tan");
                $rapor[] = ['tablo' => $ad, 'durum' => 'eklendi', 'mesaj' => 'Kolon eklendi.'];
            } catch (PDOException $e) {
                error_log('[usip_migrate] ' . $ad . ': ' . $e->getMessage());
                $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Kolon eklenemedi (ayrıntı sunucu günlüğünde).'];
            }
        }
    }
    return $rapor;
}

// ── küçük yardımcılar ────────────────────────────────────────────────────────

/** Boşlukları tekler, kırpar, azami uzunluğa böler (çok baytlı güvenli). */
function usip_ad($v, int $max): string
{
    $s = trim((string)preg_replace('/\s+/u', ' ', is_scalar($v) ? (string)$v : ''));
    return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') : $s;
}

/** Türkçe duyarsız karşılaştırma anahtarı ("KARAMAN" == "Karaman", "İ/ı" tek biçim). */
function usip_fold(string $s): string
{
    return mb_strtolower(str_replace(['İ', 'I', 'ı'], ['i', 'i', 'i'], trim($s)), 'UTF-8');
}

/** "1.234,5" / "1234.5" / 12 → float; geçersiz → null. */
function usip_num($v): ?float
{
    if (is_int($v) || is_float($v)) return is_finite((float)$v) ? (float)$v : null;
    $s = preg_replace('/\s+/', '', (string)$v) ?? '';
    if ($s === '') return null;
    if (str_contains($s, ',')) $s = str_replace(',', '.', str_replace('.', '', $s));
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);   // 1.234.567 → binlik
    return preg_match('/^\d+(\.\d+)?$/', $s) ? (float)$s : null;
}

function usip_plaka(string $s): string
{
    return mb_substr(preg_replace('/[^A-Za-z0-9\-]/', '', tr_upper($s)) ?? '', 0, 20, 'UTF-8');
}

function usip_telefon(string $s): ?string
{
    $s = trim((string)preg_replace('/[^0-9+()\-. ]/', '', $s));
    $s = (string)preg_replace('/ {2,}/', ' ', $s);
    return $s === '' ? null : mb_substr($s, 0, 40, 'UTF-8');
}

/** Sayı → "1.234" (tam sayı, KG ekranda virgülsüz) */
function usip_tam($v): string { return number_format((float)$v, 0, ',', '.'); }
/** Sayı → "1.234,56" */
function usip_para($v): string { return number_format((float)$v, 2, ',', '.'); }

/** [{kalibre,palet}, …] → "9 PLT 8" satırları */
function usip_kalibre_metin(array $kal, string $ayrac = ', '): string
{
    $p = [];
    foreach ($kal as $k) $p[] = (string)$k['kalibre'] . ' PLT ' . usip_tam($k['palet']);
    return implode($ayrac, $p);
}

/**
 * TEK hesap yeri. @return array{palet:int,kasa:int,kg:float,tutar:float}
 * @param list<array{palet:int|float}> $kal
 */
function usip_hesap(array $kal, int $paletKasa, float $birimAgirlik, ?float $birimFiyat): array
{
    $palet = 0;
    foreach ($kal as $k) $palet += (int)$k['palet'];
    $kasa = $palet * max(0, $paletKasa);
    $kg = round($kasa * max(0.0, $birimAgirlik), 3);
    $tutar = $birimFiyat !== null ? round($kg * $birimFiyat, 2) : 0.0;
    return ['palet' => $palet, 'kasa' => $kasa, 'kg' => $kg, 'tutar' => $tutar];
}

// ── doğrulama ────────────────────────────────────────────────────────────────

/**
 * Ham istek verisini doğrular ve temiz diziye çevirir.
 * @return array{0:array<string,mixed>,1:list<string>} [temiz, hatalar]
 */
function usip_dogrula(array $in): array
{
    $h = [];
    $firma = usip_ad($in['firma'] ?? '', 150);
    $urun  = usip_ad($in['urun'] ?? '', 150);
    if ($firma === '') $h[] = 'Firma adı gerekli.';
    if ($urun === '')  $h[] = 'Ürün gerekli.';

    $tarih = trim((string)($in['teslimat_tarihi'] ?? ''));
    $dt = DateTime::createFromFormat('Y-m-d', $tarih);
    if (!$dt || $dt->format('Y-m-d') !== $tarih) { $h[] = 'Teslimat tarihi geçersiz.'; $tarih = ''; }

    $kal = []; $gorulen = [];
    $ham = $in['kalibreler'] ?? [];
    if (!is_array($ham) || !$ham) $h[] = 'En az bir kalibre ve palet adedi girin.';
    elseif (count($ham) > USIP_KALIBRE_LIMIT) $h[] = 'En fazla ' . USIP_KALIBRE_LIMIT . ' kalibre girilebilir.';
    else {
        foreach (array_values($ham) as $i => $r) {
            $no = usip_ad(is_array($r) ? ($r['kalibre'] ?? '') : '', 20);
            $pn = is_array($r) ? usip_num($r['palet'] ?? '') : null;
            $sira = $i + 1;
            if ($no === '') { $h[] = "$sira. kalibre numarası boş."; continue; }
            if ($pn === null || $pn < 1 || $pn > 9999 || floor($pn) != $pn) { $h[] = "Kalibre $no için palet adedi 1–9999 arasında tam sayı olmalı."; continue; }
            $f = usip_fold($no);
            if (isset($gorulen[$f])) { $h[] = "Kalibre $no birden fazla kez girilmiş."; continue; }
            $gorulen[$f] = true;
            $kal[] = ['kalibre' => $no, 'palet' => (int)$pn];
        }
    }

    $pk = usip_num($in['palet_kasa'] ?? '');
    if ($pk === null || $pk < 1 || $pk > 100000 || floor($pk) != $pk) { $h[] = 'Palet kasa adedi 1–100000 arasında tam sayı olmalı.'; $pk = 0; }
    $ba = usip_num($in['birim_agirlik'] ?? '');
    if ($ba === null || $ba <= 0 || $ba > 100000) { $h[] = 'Birim ağırlık (kg) sıfırdan büyük olmalı.'; $ba = 0.0; }
    $bf = null;
    if (trim((string)($in['birim_fiyat'] ?? '')) !== '') {
        $bf = usip_num($in['birim_fiyat']);
        if ($bf === null || $bf < 0 || $bf > 1000000) { $h[] = 'Birim fiyat geçersiz.'; $bf = null; }
    }

    $tel = usip_telefon((string)($in['sofor_telefon'] ?? ''));
    if ($tel !== null) {
        $hane = strlen((string)preg_replace('/\D/', '', $tel));
        if ($hane < 7 || $hane > 15) $h[] = 'Şoför telefonu 7–15 haneli olmalıdır.';
    }

    $bos = static fn($v, int $m): ?string => ($s = usip_ad($v, $m)) === '' ? null : $s;
    $tasima = $bos($in['tasima_sekli'] ?? '', 40);
    if ($tasima !== null) {
        $eslesen = null;
        foreach (usip_tasima_sekilleri() as $ts) if (usip_fold($ts) === usip_fold($tasima)) $eslesen = $ts;
        if ($eslesen === null) $h[] = 'Taşıma şekli listeden seçilmeli.';
        $tasima = $eslesen;
    }
    $temiz = [
        'firma' => $firma, 'urun' => $urun, 'teslimat_tarihi' => $tarih,
        'kalibreler' => $kal,
        'palet_cinsi' => $bos($in['palet_cinsi'] ?? '', 100), 'kasa_cinsi' => $bos($in['kasa_cinsi'] ?? '', 100),
        'palet_kasa' => (int)$pk, 'birim_agirlik' => (float)$ba, 'birim_fiyat' => $bf,
        'nakliyeci' => $bos($in['nakliyeci'] ?? '', 150),
        'cekici_plaka' => ($p = usip_plaka((string)($in['cekici_plaka'] ?? ''))) === '' ? null : $p,
        'dorse_plaka' => ($p = usip_plaka((string)($in['dorse_plaka'] ?? ''))) === '' ? null : $p,
        'sofor_adi' => $bos($in['sofor_adi'] ?? '', 150), 'sofor_telefon' => $tel,
        'yurtici_gumruk' => $bos($in['yurtici_gumruk'] ?? '', 150), 'navlun_no' => $bos($in['navlun_no'] ?? '', 60),
        'siparis_no' => $bos($in['siparis_no'] ?? '', 60), 'siparis_firma' => $bos($in['siparis_firma'] ?? '', 150),
        'tasima_sekli' => $tasima, 'lot_no' => $bos($in['lot_no'] ?? '', 60),
        'teslim_yeri' => $bos($in['teslim_yeri'] ?? '', 150), 'teslim_sekli' => usip_kod((string)($in['teslim_sekli'] ?? '')),
        'aciklama' => $bos($in['aciklama'] ?? '', 1000),
    ];
    return [$temiz, $h];
}

// ── öneri / referans listeleri ───────────────────────────────────────────────

/**
 * Firma · Ürün · Palet cinsi · Kasa cinsi öneri kaynakları.
 *  etiket: hks (HKS ürün kataloğu) · tanim (Tanımlar sayfası) · kayit (önceki siparişlerde kullanılmış)
 * Aynı ad (TR-duyarsız) tek satırdır; öncelik hks > tanim > kayit.
 * @return array{gelen:list<array{ad:string,t:string}>,firma:list<array{ad:string,t:string}>,urun:list<array{ad:string,t:string}>,palet:list<array{ad:string,t:string}>,kasa:list<array{ad:string,t:string}>}
 */
function usip_referanslar(PDO $pdo): array
{
    $liste = ['firma' => [], 'urun' => [], 'palet' => [], 'kasa' => [], 'gelen' => []];
    $ekle = static function (string $alan, string $ad, string $t) use (&$liste): void {
        $ad = usip_ad($ad, 150);
        if ($ad === '') return;
        $f = usip_fold($ad);
        if (!isset($liste[$alan][$f])) $liste[$alan][$f] = ['ad' => $ad, 't' => $t];
    };
    try { foreach ((function_exists('bb_katalog') ? (bb_katalog()['urunler'] ?? []) : []) as $u) $ekle('urun', (string)($u['ad'] ?? ''), 'hks'); }
    catch (Throwable $e) { /* katalog yoksa serbest metin çalışır */ }
    foreach (['firma' => 'firma', 'urun' => 'urun', 'palet' => 'palet_tipi', 'kasa' => 'kasa_cinsi', 'gelen' => 'firma'] as $alan => $tip) {
        try { foreach (get_definitions_by_type($tip) as $r) $ekle($alan, (string)$r['name'], 'tanim'); }
        catch (Throwable $e) { /* tanım tablosu okunamazsa sipariş geçmişi yeter */ }
    }
    $kayitKol = ['firma' => 'firma', 'urun' => 'urun', 'palet' => 'palet_cinsi', 'kasa' => 'kasa_cinsi'];
    if (usip_ek_hazir($pdo)) $kayitKol['gelen'] = 'siparis_firma';   // siparişin geldiği firma: önceki girişlerden öğrenir
    foreach ($kayitKol as $alan => $kol) {
        try {
            $st = $pdo->query("SELECT DISTINCT `$kol` FROM urun_siparisleri WHERE deleted_at IS NULL AND `$kol` IS NOT NULL AND `$kol` <> '' LIMIT 1000");
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ad) $ekle($alan, (string)$ad, 'kayit');
        } catch (PDOException $e) { /* tablo yoksa boş */ }
    }
    foreach ($liste as $alan => $l) {
        $v = array_values($l);
        usort($v, static fn($a, $b) => strcmp(usip_fold($a['ad']), usip_fold($b['ad'])));
        $liste[$alan] = $v;
    }
    return $liste;
}

/** Girilen adı varsa mevcut kanonik yazımla eşler ("karaman" → "Karaman"). */
function usip_kanonik(array $secenekler, ?string $ad): ?string
{
    if ($ad === null || $ad === '') return $ad;
    $f = usip_fold($ad);
    foreach ($secenekler as $s) if (usip_fold($s['ad']) === $f) return $s['ad'];
    return $ad;
}

// ── yazma ────────────────────────────────────────────────────────────────────

/** @param array<string,mixed> $t usip_dogrula() çıktısı. @return int sipariş id */
function usip_kaydet(PDO $pdo, array $t, int $userId, ?int $id = null, string $depo = ''): int
{
    $ref = usip_referanslar($pdo);
    $t['firma'] = (string)usip_kanonik($ref['firma'], $t['firma']);
    $t['urun']  = (string)usip_kanonik($ref['urun'], $t['urun']);
    $t['palet_cinsi'] = usip_kanonik($ref['palet'], $t['palet_cinsi']);
    $t['kasa_cinsi']  = usip_kanonik($ref['kasa'], $t['kasa_cinsi']);
    $t['siparis_firma'] = usip_kanonik($ref['gelen'], $t['siparis_firma'] ?? null);
    $hs = usip_hesap($t['kalibreler'], $t['palet_kasa'], $t['birim_agirlik'], $t['birim_fiyat']);

    $kolonlar = ['firma' => $t['firma'], 'urun' => $t['urun'], 'teslimat_tarihi' => $t['teslimat_tarihi'],
        'palet_cinsi' => $t['palet_cinsi'], 'kasa_cinsi' => $t['kasa_cinsi'], 'palet_kasa' => $t['palet_kasa'],
        'birim_agirlik' => $t['birim_agirlik'], 'birim_fiyat' => $t['birim_fiyat'],
        'toplam_palet' => $hs['palet'], 'toplam_kasa' => $hs['kasa'], 'toplam_kg' => $hs['kg'], 'tutar' => $hs['tutar'],
        'nakliyeci' => $t['nakliyeci'], 'cekici_plaka' => $t['cekici_plaka'], 'dorse_plaka' => $t['dorse_plaka'],
        'sofor_adi' => $t['sofor_adi'], 'sofor_telefon' => $t['sofor_telefon'],
        'yurtici_gumruk' => $t['yurtici_gumruk'], 'navlun_no' => $t['navlun_no'], 'aciklama' => $t['aciklama']];
    foreach (['a', 'b'] as $grup) {   // kolonlar kurulmadıysa o grubun alanları yazılmaz (migrate.php kartı)
        if (!usip_ek_hazir($pdo, $grup)) continue;
        foreach (usip_ek_kolonlar() as $c) if (($c[2] ?? 'a') === $grup) $kolonlar[$c[0]] = $t[$c[0]] ?? null;
    }

    $sahip = !$pdo->inTransaction();
    if ($sahip) $pdo->beginTransaction();
    try {
        if ($id === null) {
            $kolonlar += ['depo' => $depo, 'created_by' => $userId];
            $ad = array_keys($kolonlar);
            $pdo->prepare('INSERT INTO urun_siparisleri (`' . implode('`,`', $ad) . '`) VALUES (' . implode(',', array_fill(0, count($ad), '?')) . ')')
                ->execute(array_values($kolonlar));
            $id = (int)$pdo->lastInsertId();
        } else {
            $kolonlar += ['updated_by' => $userId];
            $set = implode(',', array_map(static fn($k) => "`$k` = ?", array_keys($kolonlar)));
            $pdo->prepare("UPDATE urun_siparisleri SET $set, updated_at = ? WHERE id = ? AND deleted_at IS NULL")
                ->execute([...array_values($kolonlar), date('Y-m-d H:i:s'), $id]);
            $pdo->prepare('DELETE FROM urun_siparis_kalibreler WHERE siparis_id = ?')->execute([$id]);
        }
        $ek = $pdo->prepare('INSERT INTO urun_siparis_kalibreler (siparis_id, sira, kalibre, palet) VALUES (?,?,?,?)');
        foreach ($t['kalibreler'] as $i => $k) $ek->execute([$id, $i + 1, $k['kalibre'], $k['palet']]);
        if ($sahip) $pdo->commit();
    } catch (Throwable $e) {
        if ($sahip && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $id;
}

/** Soft silme (satır kalır; listede ve referanslarda görünmez). */
function usip_sil(PDO $pdo, int $id, int $userId): bool
{
    $st = $pdo->prepare('UPDATE urun_siparisleri SET deleted_at = ?, deleted_by = ? WHERE id = ? AND deleted_at IS NULL');
    $st->execute([date('Y-m-d H:i:s'), $userId, $id]);
    return $st->rowCount() > 0;
}

// ── okuma ────────────────────────────────────────────────────────────────────

/** Tek sipariş (tüm alanlar + kalibreler); yok/silinmiş → null. Depo kapısını ÇAĞIRAN uygular. */
function usip_getir(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM urun_siparisleri WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $k = $pdo->prepare('SELECT kalibre, palet FROM urun_siparis_kalibreler WHERE siparis_id = ? ORDER BY sira, id');
    $k->execute([$id]);
    $r['kalibreler'] = array_map(static fn($x) => ['kalibre' => (string)$x['kalibre'], 'palet' => (int)$x['palet']], $k->fetchAll(PDO::FETCH_ASSOC));
    return $r;
}

/**
 * Liste filtresi (liste + yazdırma ORTAK). GET: q (arama).
 * @return array{where:string,par:array<string,mixed>,q:string}
 */
function usip_filtre(array $get, ?PDO $pdo = null): array
{
    $q = usip_ad($get['q'] ?? '', 80);
    [$dSql, $dPar] = depo_sql_column('s.depo');
    $where = 's.deleted_at IS NULL' . $dSql;
    $par = $dPar;
    if ($q !== '') {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $alanlar = ['s.firma', 's.urun', 's.nakliyeci', 's.cekici_plaka', 's.dorse_plaka', 's.navlun_no', 's.sofor_adi', 's.palet_cinsi', 's.kasa_cinsi'];
        if ($pdo !== null && usip_ek_hazir($pdo)) array_push($alanlar, 's.siparis_no', 's.siparis_firma', 's.lot_no');
        if ($pdo !== null && usip_ek_hazir($pdo, 'b')) array_push($alanlar, 's.teslim_yeri', 's.teslim_sekli');
        $parcalar = [];
        foreach ($alanlar as $i => $a) { $parcalar[] = "$a LIKE :uq$i ESCAPE '!'"; $par[":uq$i"] = $like; }
        $parcalar[] = "EXISTS (SELECT 1 FROM urun_siparis_kalibreler k WHERE k.siparis_id = s.id AND k.kalibre LIKE :uqk ESCAPE '!')";
        $par[':uqk'] = $like;
        $where .= ' AND (' . implode(' OR ', $parcalar) . ')';
    }
    return ['where' => $where, 'par' => $par, 'q' => $q];
}

/** @return array{satirlar:list<array<string,mixed>>,toplam:array<string,mixed>,sayfa:int,sayfa_say:int,adet:int} */
function usip_liste(PDO $pdo, array $F, int $sayfa): array
{
    $c = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(s.toplam_palet),0) AS palet, COALESCE(SUM(s.toplam_kasa),0) AS kasa,
                               COALESCE(SUM(s.toplam_kg),0) AS kg, COALESCE(SUM(s.tutar),0) AS tutar
                        FROM urun_siparisleri s WHERE ' . $F['where']);
    $c->execute($F['par']);
    $top = $c->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'palet' => 0, 'kasa' => 0, 'kg' => 0, 'tutar' => 0];
    $adet = (int)$top['n'];
    $sayfaSay = max(1, (int)ceil($adet / USIP_SAYFA_ADEDI));
    $sayfa = max(1, min($sayfa, $sayfaSay));
    $ek = (usip_ek_hazir($pdo) ? ', s.siparis_no, s.siparis_firma, s.tasima_sekli, s.lot_no' : '')
        . (usip_ek_hazir($pdo, 'b') ? ', s.teslim_yeri, s.teslim_sekli' : '');
    $st = $pdo->prepare('SELECT s.id, s.firma, s.urun, s.teslimat_tarihi, s.palet_cinsi, s.kasa_cinsi, s.birim_fiyat, s.toplam_palet, s.toplam_kasa,
                                s.toplam_kg, s.tutar, s.nakliyeci, s.cekici_plaka, s.dorse_plaka, s.navlun_no' . $ek . '
                         FROM urun_siparisleri s WHERE ' . $F['where'] . '
                         ORDER BY s.teslimat_tarihi DESC, s.id DESC LIMIT ' . USIP_SAYFA_ADEDI . ' OFFSET ' . (($sayfa - 1) * USIP_SAYFA_ADEDI));
    $st->execute($F['par']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $kal = usip_kalibre_haritasi($pdo, array_map(static fn($r) => (int)$r['id'], $rows));
    foreach ($rows as &$r) $r['kalibreler'] = $kal[(int)$r['id']] ?? [];
    unset($r);
    return ['satirlar' => $rows, 'toplam' => $top, 'sayfa' => $sayfa, 'sayfa_say' => $sayfaSay, 'adet' => $adet];
}

/** @param list<int> $idler @return array<int,list<array{kalibre:string,palet:int}>> */
function usip_kalibre_haritasi(PDO $pdo, array $idler): array
{
    if (!$idler) return [];
    $st = $pdo->prepare('SELECT siparis_id, kalibre, palet FROM urun_siparis_kalibreler WHERE siparis_id IN (' . implode(',', array_fill(0, count($idler), '?')) . ') ORDER BY siparis_id, sira, id');
    $st->execute(array_values($idler));
    $h = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $h[(int)$r['siparis_id']][] = ['kalibre' => (string)$r['kalibre'], 'palet' => (int)$r['palet']];
    return $h;
}

// ── yazdırma / PDF / WP resmi verisi (assets/siparis_yazdir.js sözleşmesi) ───

/** @return array<string,mixed> siparis_yazdir.js'in beklediği JSON (kolonlar/satirlar/toplam/…) */
function usip_yazdir_veri(PDO $pdo, array $F, array $ctx): array
{
    $st = $pdo->prepare('SELECT s.* FROM urun_siparisleri s WHERE ' . $F['where'] . ' ORDER BY s.teslimat_tarihi DESC, s.id DESC LIMIT ' . (USIP_YAZDIR_LIMIT + 1));
    $st->execute($F['par']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $kesildi = count($rows) > USIP_YAZDIR_LIMIT;
    if ($kesildi) $rows = array_slice($rows, 0, USIP_YAZDIR_LIMIT);
    $kal = usip_kalibre_haritasi($pdo, array_map(static fn($r) => (int)$r['id'], $rows));

    $bugun = (string)($ctx['bugun'] ?? date('Y-m-d'));
    $tp = $tk = 0; $tkg = $tt = 0.0;
    $satirlar = [];
    foreach ($rows as $r) {
        $tp += (int)$r['toplam_palet']; $tk += (int)$r['toplam_kasa']; $tkg += (float)$r['toplam_kg']; $tt += (float)$r['tutar'];
        $plaka = implode(' / ', array_filter([(string)$r['cekici_plaka'], (string)$r['dorse_plaka']], static fn($x) => $x !== ''));
        $sofor = trim((string)$r['sofor_adi'] . ((string)$r['sofor_telefon'] !== '' ? "\n" . $r['sofor_telefon'] : ''));
        $tarih = (string)$r['teslimat_tarihi'];
        $satirlar[] = [
            'h' => [
                $tarih !== '' ? date('d.m.Y', strtotime($tarih)) : '',
                (string)$r['firma'], (string)$r['urun'],
                usip_kalibre_metin($kal[(int)$r['id']] ?? [], "\n"),
                usip_tam($r['toplam_palet']), usip_tam($r['toplam_kasa']), usip_tam($r['toplam_kg']),
                $r['birim_fiyat'] !== null ? usip_para($r['birim_fiyat']) : '', $r['birim_fiyat'] !== null ? usip_para($r['tutar']) : '',
                (string)$r['nakliyeci'], $plaka, $sofor,
            ],
            'gecmis' => false,
            'bugun' => $tarih === $bugun,
            'gun' => $tarih,
        ];
    }
    $kolonlar = [
        ['k' => 'tarih',     'l' => 'Teslimat',        't' => 'metin', 'w' => 8],
        ['k' => 'firma',     'l' => 'Firma',           't' => 'metin', 'w' => 14],
        ['k' => 'urun',      'l' => 'Ürün',            't' => 'metin', 'w' => 8],
        ['k' => 'kalibre',   'l' => 'Kalibre / Palet', 't' => 'metin', 'w' => 11],
        ['k' => 'palet',     'l' => 'Palet',           't' => 'sayi',  'w' => 5],
        ['k' => 'kasa',      'l' => 'Kasa',            't' => 'sayi',  'w' => 6],
        ['k' => 'kg',        'l' => 'Miktar (kg)',     't' => 'sayi',  'w' => 8],
        ['k' => 'fiyat',     'l' => 'Fiyat (TL/kg)',   't' => 'sayi',  'w' => 6],
        ['k' => 'tutar',     'l' => 'Tutar (TL)',      't' => 'sayi',  'w' => 9],
        ['k' => 'nakliyeci', 'l' => 'Nakliyeci',       't' => 'metin', 'w' => 8],
        ['k' => 'plaka',     'l' => 'Plaka',           't' => 'metin', 'w' => 8],
        ['k' => 'sofor',     'l' => 'Şoför / Tel',     't' => 'metin', 'w' => 9],
    ];
    $notlar = [];
    if ($kesildi) $notlar[] = 'Liste ilk ' . USIP_YAZDIR_LIMIT . ' siparişle sınırlandı; filtreyi daraltın.';
    $basim = (string)($ctx['basim'] ?? date('d.m.Y H:i'));
    return [
        'ok' => true,
        'kapsam' => 'urun',
        'baslik' => (string)($ctx['baslik'] ?? 'Ürün Siparişleri'),
        'dosya' => 'Urun-Siparisleri-' . date('Y-m-d'),
        'basim' => $basim,
        'depo' => (string)($ctx['depo'] ?? ''),
        'filtre' => (string)($ctx['filtre'] ?? ''),
        'adet' => count($satirlar),
        'gecmis_say' => 0,
        'kesildi' => $kesildi,
        'limit' => USIP_YAZDIR_LIMIT,
        'notlar' => $notlar,
        'kolonlar' => $kolonlar,
        'satirlar' => $satirlar,
        'toplam' => ['palet' => usip_tam($tp), 'kasa' => usip_tam($tk), 'kg' => usip_tam($tkg), 'fiyat' => '', 'tutar' => usip_para($tt)],
    ];
}
