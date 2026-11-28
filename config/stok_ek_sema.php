<?php
// =========================================================
// config/stok_ek_sema.php — Stok kartı EK alanları şeması (v611; include-only; yalnız fonksiyon bildirir)
//
// stok_kartlar'a 5 kolon: ambalaj_tip_id (→ stok_tanimlar tur='ambalaj_tip') · birim_dara_kg (DECIMAL(10,3), Palet/Mal Kabul dara formülünün
// birim darası) · hks_urun_id + hks_urun_ad (HKS ürün kataloğu id'si + ad kopyası; halkayit/ tablolarına YAZILMAZ) · hizli (Hızlı eklendi bayrağı).
// Ayrıca eski Tanımlar bağı stok_kartlar.md_id (+ idx_stk_md) KALDIRILIR ve 'ambalaj_tip' sistem tohumu (Kasa, Palet; kod dolu = silinmez, adı kilitli) eklenir.
// Şema YALNIZ migrate.php › "Stok — Ambalaj tipi · Dara · HKS ürün · Hızlı ekleme" kartından kurulur (stok_ek_migrate; açık GO ile).
// Sayfalar/çekirdek ALTER ATMAZ: kolonlar yokken stok_ek_hazir() false → yeni alanlar gizli, kayıt onları yazmaz (fail-soft).
// stok_tablolar() yeni kurulum DDL'i bu kolonları zaten içerir; ALTER'lar yalnız burada.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** @return list<array{0:string,1:string}> [kolon, tanım] — sıra = ALTER sırası */
function stok_ek_kolonlari(): array
{
    return [
        ['ambalaj_tip_id', 'INT NULL DEFAULT NULL'],
        ['birim_dara_kg', 'DECIMAL(10,3) NULL DEFAULT NULL'],
        ['hks_urun_id', 'VARCHAR(40) NULL DEFAULT NULL'],
        ['hks_urun_ad', 'VARCHAR(150) NULL DEFAULT NULL'],
        ['hizli', 'TINYINT(1) NOT NULL DEFAULT 0'],
    ];
}

/** @return array<string,string> indeks adı → kolon */
function stok_ek_indeksleri(): array
{
    return ['idx_stk_ambalaj' => 'ambalaj_tip_id', 'idx_stk_hks' => 'hks_urun_id', 'idx_stk_hizli' => 'hizli'];
}

/** Ambalaj tipi SİSTEM tohumu (kod dolu = silinemez, adı kilitli; pasifleştirilebilir). @return list<array{0:string,1:string}> [ad, kod] */
function stok_ambalaj_tohumu(): array
{
    return [['Kasa', 'kasa'], ['Palet', 'palet']];
}

function stok_ek_kolon_var(PDO $pdo, string $kolon): bool
{
    try { $pdo->query("SELECT `$kolon` FROM `stok_kartlar` LIMIT 0"); return true; } catch (PDOException $e) { return false; }
}

function stok_ek_indeks_var(PDO $pdo, string $ad): bool
{
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ?");
        } else {
            $st = $pdo->prepare('SHOW INDEX FROM `stok_kartlar` WHERE Key_name = ?');
        }
        $st->execute([$ad]);
        return $st->fetchColumn() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

/** Beş ek kolon da var mı? İstek içi önbellek (yalnız olumlu sonuç; PDO başına → migrasyondan sonra sayfa hemen görür). */
function stok_ek_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        $pdo->query('SELECT ambalaj_tip_id, birim_dara_kg, hks_urun_id, hks_urun_ad, hizli FROM `stok_kartlar` LIMIT 0');
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Kart durumu (önbelleksiz; migrate kartı için). @return array{kolonlar:bool,indeksler:bool,md_id_yok:bool,tohum:bool,hazir:bool} */
function stok_ek_durum(PDO $pdo): array
{
    $kol = true;
    foreach (stok_ek_kolonlari() as [$k]) if (!stok_ek_kolon_var($pdo, $k)) { $kol = false; break; }
    $ix = $kol;
    if ($ix) foreach (array_keys(stok_ek_indeksleri()) as $a) if (!stok_ek_indeks_var($pdo, $a)) { $ix = false; break; }
    $mdYok = !stok_ek_kolon_var($pdo, 'md_id');
    $tohum = true;
    try {
        $st = $pdo->prepare("SELECT 1 FROM `stok_tanimlar` WHERE tur = 'ambalaj_tip' AND kod = ?");
        foreach (stok_ambalaj_tohumu() as [, $kod]) { $st->execute([$kod]); if ($st->fetchColumn() === false) { $tohum = false; break; } }
    } catch (PDOException $e) {
        $tohum = false;
    }
    return ['kolonlar' => $kol, 'indeksler' => $ix, 'md_id_yok' => $mdYok, 'tohum' => $tohum, 'hazir' => $kol && $ix && $mdYok && $tohum];
}

/**
 * İdempotent ek şema (yalnız migrate.php kartı). Adımlar: 5 kolon ADD · 3 indeks · md_id (+ idx_stk_md) DROP · ambalaj_tip sistem tohumu.
 * Her adım bir sonuç satırı: durum var|eklendi|kaldirildi|hata. Stok tabloları yoksa tek "hata" satırı (önce Stok kartı).
 * @return list<array{adim:string,durum:string,mesaj:string}>
 */
function stok_ek_migrate(PDO $pdo): array
{
    if (!stok_hazir($pdo)) return [['adim' => 'stok_kartlar', 'durum' => 'hata', 'mesaj' => 'Önce Stok (muhasebe) tablolarını oluşturun.']];
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $rapor = [];
    foreach (stok_ek_kolonlari() as [$kolon, $tanim]) {
        if (stok_ek_kolon_var($pdo, $kolon)) { $rapor[] = ['adim' => "stok_kartlar.$kolon", 'durum' => 'var', 'mesaj' => 'Kolon zaten mevcut.']; continue; }
        try {
            $pdo->exec("ALTER TABLE `stok_kartlar` ADD COLUMN `$kolon` $tanim");
            $rapor[] = ['adim' => "stok_kartlar.$kolon", 'durum' => 'eklendi', 'mesaj' => 'Kolon eklendi.'];
        } catch (PDOException $e) {
            error_log('[stok_ek_migrate] ' . $kolon . ': ' . $e->getMessage());
            $rapor[] = ['adim' => "stok_kartlar.$kolon", 'durum' => 'hata', 'mesaj' => 'Kolon eklenemedi (ayrıntı sunucu günlüğünde).'];
        }
    }
    foreach (stok_ek_indeksleri() as $ad => $kolon) {
        if (stok_ek_indeks_var($pdo, $ad)) { $rapor[] = ['adim' => "stok_kartlar.$ad", 'durum' => 'var', 'mesaj' => 'İndeks zaten mevcut.']; continue; }
        if (!stok_ek_kolon_var($pdo, $kolon)) { $rapor[] = ['adim' => "stok_kartlar.$ad", 'durum' => 'hata', 'mesaj' => 'Kolon olmadığı için indeks eklenemedi.']; continue; }
        try {
            $pdo->exec("CREATE INDEX `$ad` ON `stok_kartlar` (`$kolon`)");
            $rapor[] = ['adim' => "stok_kartlar.$ad", 'durum' => 'eklendi', 'mesaj' => 'İndeks eklendi.'];
        } catch (PDOException $e) {
            error_log('[stok_ek_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['adim' => "stok_kartlar.$ad", 'durum' => 'hata', 'mesaj' => 'İndeks eklenemedi (ayrıntı sunucu günlüğünde).'];
        }
    }
    // Eski Tanımlar bağı (material_definitions id'si) — hiçbir kod okumuyor/yazmıyor; kaldırılır.
    if (!stok_ek_kolon_var($pdo, 'md_id')) {
        $rapor[] = ['adim' => 'stok_kartlar.md_id', 'durum' => 'var', 'mesaj' => 'Eski kolon zaten yok.'];
    } else {
        try {
            if (stok_ek_indeks_var($pdo, 'idx_stk_md')) $pdo->exec($sqlite ? 'DROP INDEX `idx_stk_md`' : 'ALTER TABLE `stok_kartlar` DROP INDEX `idx_stk_md`');
            $pdo->exec('ALTER TABLE `stok_kartlar` DROP COLUMN `md_id`');
            $rapor[] = ['adim' => 'stok_kartlar.md_id', 'durum' => 'kaldirildi', 'mesaj' => 'Eski Tanımlar bağı (md_id + idx_stk_md) kaldırıldı.'];
        } catch (PDOException $e) {
            error_log('[stok_ek_migrate] md_id: ' . $e->getMessage());
            $rapor[] = ['adim' => 'stok_kartlar.md_id', 'durum' => 'hata', 'mesaj' => 'Eski kolon kaldırılamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    // Sistem tohumu: kod ile aranır; aynı ADLA kullanıcı satırı varsa ona dokunulmaz (UNIQUE tur+ad).
    try {
        $kodBul = $pdo->prepare("SELECT 1 FROM `stok_tanimlar` WHERE tur = 'ambalaj_tip' AND kod = ?");
        $adlar = $pdo->query("SELECT ad FROM `stok_tanimlar` WHERE tur = 'ambalaj_tip'")->fetchAll(PDO::FETCH_COLUMN);
        $ins = $pdo->prepare('INSERT INTO `stok_tanimlar` (tur, ad, kod, sira, aktif, created_at) VALUES (?, ?, ?, ?, 1, ?)');
        $sira = 0;
        foreach (stok_ambalaj_tohumu() as [$ad, $kod]) {
            $sira += 10;
            $kodBul->execute([$kod]);
            if ($kodBul->fetchColumn() !== false) { $rapor[] = ['adim' => "ambalaj_tip.$kod", 'durum' => 'var', 'mesaj' => 'Sistem tanımı zaten mevcut.']; continue; }
            $ayni = false;
            foreach ($adlar as $a) if (cari_fold((string)$a) === cari_fold($ad)) { $ayni = true; break; }
            if ($ayni) { $rapor[] = ['adim' => "ambalaj_tip.$kod", 'durum' => 'hata', 'mesaj' => 'Aynı adla elle eklenmiş bir ambalaj tipi var; dokunulmadı (adını değiştirip kartı yeniden çalıştırın).']; continue; }
            $ins->execute(['ambalaj_tip', $ad, $kod, $sira, date('Y-m-d H:i:s')]);
            $rapor[] = ['adim' => "ambalaj_tip.$kod", 'durum' => 'eklendi', 'mesaj' => 'Sistem tanımı eklendi (' . $ad . ').'];
        }
    } catch (PDOException $e) {
        error_log('[stok_ek_migrate] tohum: ' . $e->getMessage());
        $rapor[] = ['adim' => 'ambalaj_tip', 'durum' => 'hata', 'mesaj' => 'Ambalaj tipi tohumu yazılamadı (ayrıntı sunucu günlüğünde).'];
    }
    return $rapor;
}
