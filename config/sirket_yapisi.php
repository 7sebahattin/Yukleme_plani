<?php
// =========================================================
// config/sirket_yapisi.php — Şirket · Şube · Depo bilgisi (Faz 0.1, v505; include-only)
//
// ALTYAPIDIR: içerik (şirket/şube/depo) kullanıcı tarafından ekranlardan (ya da ileride Logo Excel'iyle) girilir.
// Kodda HİÇBİR şirket adı/kodu sabit yazılmaz; sayı sınırı yoktur.
//   • sirketler : ayrı VKN = ayrı şirket. Pasife alınır, SİLİNMEZ (geçmiş kayıtlar ona bağlanacak).
//   • subeler   : şirkete bağlı işyeri. Kod şirket içinde tekil. Pasife alınır, SİLİNMEZ.
//   • depo_detay / depo_sirket : MEVCUT depo tanımlarının (material_definitions type='depo') ek bilgisi —
//     tür (kendi/fason/anlaşmalı), şube, kullanabilen şirketler. Depo tanımının kendisine DOKUNULMAZ.
//     depo_sirket'te hiç satır yoksa depo kısıtsızdır (tüm AKTİF şirketler kullanabilir).
// Şema yalnız migrate.php "Şirket · Şube · Depo" kartından kurulur (sayfalar şema değiştirmez; ekleyici, ALTER yok, FK yok).
// Yazma kapısı sayfalarda (is_admin + CSRF); burada kendi doğrulaması + audit. Hassas veri yok.
// =========================================================
declare(strict_types=1);
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** Depo türleri (anahtar → etiket). */
const SIRKET_DEPO_TURLERI = [
    'kendi'     => 'Kendi deposu',
    'fason'     => 'Fason (başkasının tesisinde işleme)',
    'anlasmali' => 'Anlaşmalı depo',
];

/** @return array<string,string> tablo adı → CREATE TABLE */
function sirket_tablolar(): array
{
    $son = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';
    $t = [];
    $t['sirketler'] = "CREATE TABLE IF NOT EXISTS `sirketler` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `kod`             VARCHAR(12)  NOT NULL,
        `unvan`           VARCHAR(200) NOT NULL,
        `kisa_ad`         VARCHAR(60)  NULL DEFAULT NULL,
        `vergi_no`        VARCHAR(11)  NULL DEFAULT NULL,
        `vergi_dairesi`   VARCHAR(100) NULL DEFAULT NULL,
        `adres`           VARCHAR(255) NULL DEFAULT NULL,
        `sehir`           VARCHAR(80)  NULL DEFAULT NULL,
        `ulke`            VARCHAR(80)  NULL DEFAULT NULL,
        `telefon`         VARCHAR(40)  NULL DEFAULT NULL,
        `eposta`          VARCHAR(150) NULL DEFAULT NULL,
        `e_belge_etiketi` VARCHAR(150) NULL DEFAULT NULL,
        `logo_firma_no`   VARCHAR(10)  NULL DEFAULT NULL,
        `renk`            VARCHAR(7)   NULL DEFAULT NULL,
        `notlar`          VARCHAR(500) NULL DEFAULT NULL,
        `aktif`           TINYINT(1)   NOT NULL DEFAULT 1,
        `created_by`      INT          NULL DEFAULT NULL,
        `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_by`      INT          NULL DEFAULT NULL,
        `updated_at`      DATETIME     NULL DEFAULT NULL,
        UNIQUE KEY `uq_sirket_kod` (`kod`)
    )" . $son;
    $t['subeler'] = "CREATE TABLE IF NOT EXISTS `subeler` (
        `id`             INT AUTO_INCREMENT PRIMARY KEY,
        `sirket_id`      INT          NOT NULL,
        `kod`            VARCHAR(12)  NOT NULL,
        `ad`             VARCHAR(120) NOT NULL,
        `adres`          VARCHAR(255) NULL DEFAULT NULL,
        `sehir`          VARCHAR(80)  NULL DEFAULT NULL,
        `telefon`        VARCHAR(40)  NULL DEFAULT NULL,
        `logo_isyeri_no` VARCHAR(10)  NULL DEFAULT NULL,
        `aktif`          TINYINT(1)   NOT NULL DEFAULT 1,
        `created_by`     INT          NULL DEFAULT NULL,
        `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_by`     INT          NULL DEFAULT NULL,
        `updated_at`     DATETIME     NULL DEFAULT NULL,
        UNIQUE KEY `uq_sube_kod` (`sirket_id`, `kod`)
    )" . $son;
    $t['depo_detay'] = "CREATE TABLE IF NOT EXISTS `depo_detay` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `depo_id`    INT          NOT NULL,
        `tur`        VARCHAR(12)  NOT NULL DEFAULT 'kendi',
        `sube_id`    INT          NULL DEFAULT NULL,
        `aciklama`   VARCHAR(255) NULL DEFAULT NULL,
        `updated_by` INT          NULL DEFAULT NULL,
        `updated_at` DATETIME     NULL DEFAULT NULL,
        UNIQUE KEY `uq_depo_detay` (`depo_id`)
    )" . $son;
    $t['depo_sirket'] = "CREATE TABLE IF NOT EXISTS `depo_sirket` (
        `id`        INT AUTO_INCREMENT PRIMARY KEY,
        `depo_id`   INT NOT NULL,
        `sirket_id` INT NOT NULL,
        UNIQUE KEY `uq_depo_sirket` (`depo_id`, `sirket_id`)
    )" . $son;
    return $t;
}

function sirket_tablo_var(PDO $pdo, string $tablo): bool
{
    try {
        $pdo->query('SELECT 1 FROM `' . str_replace('`', '', $tablo) . '` LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Dört tablo da var mı? (istek başına bir kez sorulur) */
function sirket_yapisi_hazir(PDO $pdo): bool
{
    static $onbellek = [];
    $k = spl_object_id($pdo);
    if (isset($onbellek[$k])) return $onbellek[$k];
    foreach (array_keys(sirket_tablolar()) as $ad) {
        if (!sirket_tablo_var($pdo, $ad)) return false;   // eksikse önbelleğe YAZMA (migrasyon sonrası hemen görünsün)
    }
    return $onbellek[$k] = true;
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> durum: var|olusturuldu|hata */
function sirket_yapisi_migrate(PDO $pdo): array
{
    $rapor = [];
    foreach (sirket_tablolar() as $ad => $ddl) {
        if (sirket_tablo_var($pdo, $ad)) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            $pdo->exec($ddl);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
        } catch (PDOException $e) {
            error_log('[sirket_yapisi_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}

// ── Ortak yardımcılar ────────────────────────────────────────────────

/** Kırpılmış, uzunluğu sınırlı metin ('' → ''). */
function sirket_metin(mixed $v, int $max): string
{
    $s = trim((string)($v ?? ''));
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
    return mb_substr($s, 0, $max, 'UTF-8');
}

/** '' → null (boş alan veritabanında NULL). */
function sirket_bos_null(string $s): ?string
{
    return $s === '' ? null : $s;
}

/** Kod: yalnız A-Z 0-9, büyük harfe çevrilir (Türkçe karakter KABUL EDİLMEZ — belge numarasında kullanılacak). */
function sirket_kod_temizle(mixed $v): string
{
    // 40'a kadar okunur: 12'de KIRPILSAYDI uzun kod sessizce kısalıp geçerli görünürdü; doğrulama uzunluğu reddeder.
    return strtoupper(sirket_metin($v, 40));
}

function sirket_audit(string $islem, string $modul, int $id, ?array $eski, ?array $yeni): void
{
    if (function_exists('audit_log_event')) {
        try { audit_log_event($islem, $modul, $id, $eski, $yeni); } catch (Throwable $e) { error_log('[sirket_audit] ' . $e->getMessage()); }
    }
}

// ── Şirketler ────────────────────────────────────────────────────────

/** @return array<string,string> alan adı → temizlenmiş değer (kod, unvan, … ; aktif HARİÇ) */
function sirket_girdi(array $in): array
{
    return [
        'kod'             => sirket_kod_temizle($in['kod'] ?? ''),
        'unvan'           => sirket_metin($in['unvan'] ?? '', 200),
        'kisa_ad'         => sirket_metin($in['kisa_ad'] ?? '', 60),
        'vergi_no'        => preg_replace('/\D+/', '', sirket_metin($in['vergi_no'] ?? '', 20)) ?? '',
        'vergi_dairesi'   => sirket_metin($in['vergi_dairesi'] ?? '', 100),
        'adres'           => sirket_metin($in['adres'] ?? '', 255),
        'sehir'           => sirket_metin($in['sehir'] ?? '', 80),
        'ulke'            => sirket_metin($in['ulke'] ?? '', 80),
        'telefon'         => sirket_metin($in['telefon'] ?? '', 40),
        'eposta'          => sirket_metin($in['eposta'] ?? '', 150),
        'e_belge_etiketi' => sirket_metin($in['e_belge_etiketi'] ?? '', 150),
        'logo_firma_no'   => preg_replace('/\D+/', '', sirket_metin($in['logo_firma_no'] ?? '', 10)) ?? '',
        'renk'            => strtolower(sirket_metin($in['renk'] ?? '', 7)),
        'notlar'          => sirket_metin($in['notlar'] ?? '', 500),
    ];
}

/** @return list<string> Türkçe hata iletileri (boşsa geçerli) */
function sirket_dogrula(PDO $pdo, array $g, ?int $id): array
{
    $h = [];
    if ($g['kod'] === '') $h[] = 'Şirket kodu zorunlu.';
    elseif (!preg_match('/^[A-Z0-9]{2,12}$/', $g['kod'])) $h[] = 'Şirket kodu 2–12 karakter olmalı; yalnız A–Z harfleri ve rakam (Türkçe karakter, boşluk, tire yok).';
    if ($g['unvan'] === '') $h[] = 'Unvan zorunlu.';
    if ($g['vergi_no'] !== '' && !in_array(strlen($g['vergi_no']), [10, 11], true)) $h[] = 'Vergi no 10 haneli (VKN) ya da 11 haneli (TCKN) olmalı.';
    if ($g['eposta'] !== '' && !filter_var($g['eposta'], FILTER_VALIDATE_EMAIL)) $h[] = 'E-posta adresi geçerli değil.';
    if ($g['renk'] !== '' && !preg_match('/^#[0-9a-f]{6}$/', $g['renk'])) $h[] = 'Renk #rrggbb biçiminde olmalı.';
    if ($g['kod'] !== '' && preg_match('/^[A-Z0-9]{2,12}$/', $g['kod'])) {
        $st = $pdo->prepare('SELECT id FROM sirketler WHERE kod = ? AND id <> ? LIMIT 1');
        $st->execute([$g['kod'], (int)($id ?? 0)]);
        if ($st->fetchColumn()) $h[] = 'Bu şirket kodu başka bir şirkette kullanılıyor.';
    }
    if ($g['vergi_no'] !== '') {
        $st = $pdo->prepare('SELECT unvan FROM sirketler WHERE vergi_no = ? AND id <> ? LIMIT 1');
        $st->execute([$g['vergi_no'], (int)($id ?? 0)]);
        $var = $st->fetchColumn();
        if ($var !== false) $h[] = 'Bu vergi no zaten "' . $var . '" şirketinde kayıtlı (ayrı VKN = ayrı şirket).';
    }
    return $h;
}

/**
 * Şirket ekler ($id null) ya da günceller. Aktif/pasif ayrıca sirket_aktif_yap() ile değişir.
 * @return array{ok:bool,hatalar:list<string>,id:?int}
 */
function sirket_kaydet(PDO $pdo, array $in, ?int $id, ?int $kullanici): array
{
    $g = sirket_girdi($in);
    $hatalar = sirket_dogrula($pdo, $g, $id);
    $eski = null;
    if ($id !== null) {
        $st = $pdo->prepare('SELECT * FROM sirketler WHERE id = ?');
        $st->execute([$id]);
        $eski = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($eski === null) $hatalar[] = 'Şirket bulunamadı.';
    }
    if ($hatalar) return ['ok' => false, 'hatalar' => $hatalar, 'id' => $id];

    $deger = [];
    foreach ($g as $k => $v) $deger[$k] = sirket_bos_null($v);
    $simdi = date('Y-m-d H:i:s');
    if ($id === null) {
        $kol = array_keys($deger);
        $st = $pdo->prepare('INSERT INTO sirketler (' . implode(',', $kol) . ', aktif, created_by, created_at) VALUES (' . implode(',', array_fill(0, count($kol), '?')) . ', 1, ?, ?)');
        $st->execute([...array_values($deger), $kullanici, $simdi]);
        $id = (int)$pdo->lastInsertId();
        sirket_audit('create', 'sirket', $id, null, ['kod' => $g['kod'], 'unvan' => $g['unvan']]);
    } else {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($deger)));
        $st = $pdo->prepare("UPDATE sirketler SET $set, updated_by = ?, updated_at = ? WHERE id = ?");
        $st->execute([...array_values($deger), $kullanici, $simdi, $id]);
        $degisen = [];
        foreach ($deger as $k => $v) { if ((string)($eski[$k] ?? '') !== (string)($v ?? '')) $degisen[] = $k; }
        sirket_audit('update', 'sirket', $id, ['kod' => (string)$eski['kod']], ['kod' => $g['kod'], 'degisen_alanlar' => $degisen]);
    }
    return ['ok' => true, 'hatalar' => [], 'id' => $id];
}

function sirket_aktif_yap(PDO $pdo, int $id, bool $aktif, ?int $kullanici): bool
{
    $st = $pdo->prepare('UPDATE sirketler SET aktif = ?, updated_by = ?, updated_at = ? WHERE id = ?');
    $st->execute([$aktif ? 1 : 0, $kullanici, date('Y-m-d H:i:s'), $id]);
    if ($st->rowCount() < 1) return false;
    sirket_audit('toggle', 'sirket', $id, null, ['aktif' => $aktif ? 1 : 0]);
    return true;
}

/** @return list<array<string,mixed>> kod sırasıyla; $sadece_aktif true ise yalnız aktifler */
function sirket_listele(PDO $pdo, bool $sadece_aktif = false): array
{
    $sql = 'SELECT * FROM sirketler' . ($sadece_aktif ? ' WHERE aktif = 1' : '') . ' ORDER BY aktif DESC, kod ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function sirket_getir(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM sirketler WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Şubeler ──────────────────────────────────────────────────────────

/** @return array<string,string> */
function sube_girdi(array $in): array
{
    return [
        'kod'            => sirket_kod_temizle($in['kod'] ?? ''),
        'ad'             => sirket_metin($in['ad'] ?? '', 120),
        'adres'          => sirket_metin($in['adres'] ?? '', 255),
        'sehir'          => sirket_metin($in['sehir'] ?? '', 80),
        'telefon'        => sirket_metin($in['telefon'] ?? '', 40),
        'logo_isyeri_no' => preg_replace('/\D+/', '', sirket_metin($in['logo_isyeri_no'] ?? '', 10)) ?? '',
    ];
}

/**
 * Şube ekler ($id null; şirket $in['sirket_id']'dan) ya da günceller (şirketi DEĞİŞMEZ).
 * @return array{ok:bool,hatalar:list<string>,id:?int}
 */
function sube_kaydet(PDO $pdo, array $in, ?int $id, ?int $kullanici): array
{
    $g = sube_girdi($in);
    $h = [];
    $eski = null;
    if ($id !== null) {
        $st = $pdo->prepare('SELECT * FROM subeler WHERE id = ?');
        $st->execute([$id]);
        $eski = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($eski === null) $h[] = 'Şube bulunamadı.';
        $sirketId = (int)($eski['sirket_id'] ?? 0);
    } else {
        $sirketId = (int)($in['sirket_id'] ?? 0);
        $sir = $sirketId > 0 ? sirket_getir($pdo, $sirketId) : null;
        if ($sir === null) $h[] = 'Şube için geçerli bir şirket seçin.';
        elseif ((int)$sir['aktif'] !== 1) $h[] = 'Pasif şirkete yeni şube eklenemez.';
    }
    if ($g['kod'] === '') $h[] = 'Şube kodu zorunlu.';
    elseif (!preg_match('/^[A-Z0-9]{1,12}$/', $g['kod'])) $h[] = 'Şube kodu 1–12 karakter olmalı; yalnız A–Z harfleri ve rakam.';
    if ($g['ad'] === '') $h[] = 'Şube adı zorunlu.';
    if ($sirketId > 0 && $g['kod'] !== '' && preg_match('/^[A-Z0-9]{1,12}$/', $g['kod'])) {
        $st = $pdo->prepare('SELECT id FROM subeler WHERE sirket_id = ? AND kod = ? AND id <> ? LIMIT 1');
        $st->execute([$sirketId, $g['kod'], (int)($id ?? 0)]);
        if ($st->fetchColumn()) $h[] = 'Bu şube kodu aynı şirkette zaten var.';
    }
    if ($h) return ['ok' => false, 'hatalar' => $h, 'id' => $id];

    $deger = [];
    foreach ($g as $k => $v) $deger[$k] = sirket_bos_null($v);
    $simdi = date('Y-m-d H:i:s');
    if ($id === null) {
        $kol = array_keys($deger);
        $st = $pdo->prepare('INSERT INTO subeler (sirket_id, ' . implode(',', $kol) . ', aktif, created_by, created_at) VALUES (?, ' . implode(',', array_fill(0, count($kol), '?')) . ', 1, ?, ?)');
        $st->execute([$sirketId, ...array_values($deger), $kullanici, $simdi]);
        $id = (int)$pdo->lastInsertId();
        sirket_audit('create', 'sube', $id, null, ['sirket_id' => $sirketId, 'kod' => $g['kod'], 'ad' => $g['ad']]);
    } else {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($deger)));
        $st = $pdo->prepare("UPDATE subeler SET $set, updated_by = ?, updated_at = ? WHERE id = ?");
        $st->execute([...array_values($deger), $kullanici, $simdi, $id]);
        sirket_audit('update', 'sube', $id, ['kod' => (string)$eski['kod']], ['kod' => $g['kod'], 'ad' => $g['ad']]);
    }
    return ['ok' => true, 'hatalar' => [], 'id' => $id];
}

function sube_aktif_yap(PDO $pdo, int $id, bool $aktif, ?int $kullanici): bool
{
    $st = $pdo->prepare('UPDATE subeler SET aktif = ?, updated_by = ?, updated_at = ? WHERE id = ?');
    $st->execute([$aktif ? 1 : 0, $kullanici, date('Y-m-d H:i:s'), $id]);
    if ($st->rowCount() < 1) return false;
    sirket_audit('toggle', 'sube', $id, null, ['aktif' => $aktif ? 1 : 0]);
    return true;
}

/** @return list<array<string,mixed>> $sirket_id verilirse yalnız o şirketin şubeleri; şirket kodu da döner */
function sube_listele(PDO $pdo, ?int $sirket_id = null): array
{
    $sql = 'SELECT s.*, c.kod AS sirket_kod, c.unvan AS sirket_unvan, c.aktif AS sirket_aktif FROM subeler s LEFT JOIN sirketler c ON c.id = s.sirket_id'
         . ($sirket_id ? ' WHERE s.sirket_id = ?' : '') . ' ORDER BY c.kod ASC, s.aktif DESC, s.kod ASC';
    $st = $pdo->prepare($sql);
    $st->execute($sirket_id ? [$sirket_id] : []);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function sube_getir(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM subeler WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Depo ek bilgisi ──────────────────────────────────────────────────

/**
 * Mevcut depo tanımları + ek bilgileri.
 * @return list<array{id:int,ad:string,aktif:int,tur:string,tur_tanimli:bool,sube_id:?int,aciklama:string,sirketler:list<int>}>
 */
function depo_listele(PDO $pdo): array
{
    $depolar = $pdo->query("SELECT id, name, is_active FROM material_definitions WHERE type = 'depo' ORDER BY is_active DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $detay = [];
    foreach ($pdo->query('SELECT depo_id, tur, sube_id, aciklama FROM depo_detay')->fetchAll(PDO::FETCH_ASSOC) as $r) $detay[(int)$r['depo_id']] = $r;
    $izin = [];
    foreach ($pdo->query('SELECT depo_id, sirket_id FROM depo_sirket ORDER BY sirket_id')->fetchAll(PDO::FETCH_ASSOC) as $r) $izin[(int)$r['depo_id']][] = (int)$r['sirket_id'];
    $out = [];
    foreach ($depolar as $d) {
        $i = (int)$d['id'];
        $t = $detay[$i] ?? null;
        $out[] = [
            'id' => $i, 'ad' => (string)$d['name'], 'aktif' => (int)$d['is_active'],
            'tur' => $t !== null ? (string)$t['tur'] : 'kendi', 'tur_tanimli' => $t !== null,
            'sube_id' => ($t !== null && $t['sube_id'] !== null) ? (int)$t['sube_id'] : null,
            'aciklama' => $t !== null ? (string)($t['aciklama'] ?? '') : '',
            'sirketler' => $izin[$i] ?? [],
        ];
    }
    return $out;
}

/**
 * Depo ek bilgisini yazar (tür, şube, açıklama, kullanabilen şirketler). Depo tanımının kendisi DEĞİŞMEZ.
 * @param list<int> $sirket_idler boş = kısıtsız (tüm aktif şirketler)
 * @return array{ok:bool,hatalar:list<string>}
 */
function depo_detay_kaydet(PDO $pdo, int $depo_id, string $tur, ?int $sube_id, string $aciklama, array $sirket_idler, ?int $kullanici): array
{
    $h = [];
    $st = $pdo->prepare("SELECT name FROM material_definitions WHERE id = ? AND type = 'depo'");
    $st->execute([$depo_id]);
    $ad = $st->fetchColumn();
    if ($ad === false) $h[] = 'Depo bulunamadı.';
    if (!isset(SIRKET_DEPO_TURLERI[$tur])) $h[] = 'Depo türü geçerli değil.';
    $sirket_idler = array_values(array_unique(array_filter(array_map('intval', $sirket_idler), fn($x) => $x > 0)));
    if ($sirket_idler) {
        $q = implode(',', array_fill(0, count($sirket_idler), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM sirketler WHERE id IN ($q)");
        $st->execute($sirket_idler);
        if ((int)$st->fetchColumn() !== count($sirket_idler)) $h[] = 'Seçilen şirketlerden biri bulunamadı.';
    }
    if ($sube_id !== null) {
        $sb = sube_getir($pdo, $sube_id);
        if ($sb === null) $h[] = 'Şube bulunamadı.';
        elseif ($sirket_idler && !in_array((int)$sb['sirket_id'], $sirket_idler, true)) $h[] = 'Seçilen şubenin şirketi, kullanım izni verilen şirketler arasında olmalı.';
    }
    $aciklama = sirket_metin($aciklama, 255);
    if ($h) return ['ok' => false, 'hatalar' => $h];

    $simdi = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM depo_detay WHERE depo_id = ?');
        $st->execute([$depo_id]);
        if ($st->fetchColumn()) {
            $pdo->prepare('UPDATE depo_detay SET tur = ?, sube_id = ?, aciklama = ?, updated_by = ?, updated_at = ? WHERE depo_id = ?')
                ->execute([$tur, $sube_id, sirket_bos_null($aciklama), $kullanici, $simdi, $depo_id]);
        } else {
            $pdo->prepare('INSERT INTO depo_detay (depo_id, tur, sube_id, aciklama, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$depo_id, $tur, $sube_id, sirket_bos_null($aciklama), $kullanici, $simdi]);
        }
        $pdo->prepare('DELETE FROM depo_sirket WHERE depo_id = ?')->execute([$depo_id]);
        $ek = $pdo->prepare('INSERT INTO depo_sirket (depo_id, sirket_id) VALUES (?, ?)');
        foreach ($sirket_idler as $sid) $ek->execute([$depo_id, $sid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[depo_detay_kaydet] ' . $e->getMessage());
        return ['ok' => false, 'hatalar' => ['Depo bilgisi kaydedilemedi (ayrıntı sunucu günlüğünde).']];
    }
    sirket_audit('update', 'depo_detay', $depo_id, null, ['depo' => (string)$ad, 'tur' => $tur, 'sube_id' => $sube_id, 'sirketler' => $sirket_idler]);
    return ['ok' => true, 'hatalar' => []];
}

/**
 * Bu depoyu bu şirket kullanabilir mi? Depoya hiç şirket atanmamışsa KISITSIZDIR (aktif her şirket kullanır).
 * (0.2'de aktif-şirket süzgeci bunu kullanacak.)
 */
function depo_sirket_izinli(PDO $pdo, int $depo_id, int $sirket_id): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM depo_sirket WHERE depo_id = ?');
    $st->execute([$depo_id]);
    if ((int)$st->fetchColumn() === 0) return true;
    $st = $pdo->prepare('SELECT 1 FROM depo_sirket WHERE depo_id = ? AND sirket_id = ? LIMIT 1');
    $st->execute([$depo_id, $sirket_id]);
    return (bool)$st->fetchColumn();
}
