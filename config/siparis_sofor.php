<?php
// =========================================================
// config/siparis_sofor.php — Sevkiyata elle girilen şoför adı + telefonu (v377)
// siparis_core.php sonunda yüklenir. Şema: yalnız migrate.php "Şoför alanları" kartından (siparis_core.php'de ALTER YOK).
// =========================================================
declare(strict_types=1);

/**
 * v377 — sevkiyata elle girilen ŞOFÖR adı + telefonu: 2 nullable kolon (yalnız ADD COLUMN).
 * Kendi çifti (`siparis_tablolar()` DDL'ine ve siparis_migrate()'e BİLEREK EKLENMEZ — "ALTER YOK" değişmezi);
 * kurulum yalnız migrate.php "Şoför alanları" kartından. Kolonlar yoksa alanlar gizli, sayfalar çalışmaya devam eder.
 * @return list<array{0:string,1:string}> [kolon, tanım]
 */
function siparis_sofor_kolonlari(): array
{
    return [['sofor_adi', 'VARCHAR(100) NULL DEFAULT NULL'], ['sofor_telefon', 'VARCHAR(40) NULL DEFAULT NULL']];
}

/** Şoför alanı adları (siparis_alan_tanimlari anahtarları). */
function siparis_sofor_alanlari(): array
{
    return ['sofor_adi', 'sofor_telefon'];
}

/** İki kolon da var mı (yalnız olumlu sonuç önbelleğe alınır → migrasyondan sonra sayfa hemen görür). */
function siparis_sofor_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        $pdo->query('SELECT sofor_adi, sofor_telefon FROM siparis_sevkiyatlar LIMIT 0');
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Eksik şoför kolonlarını ekler (idempotent). @return list<array{adim:string,durum:string,mesaj:string}> durum: var|eklendi|hata */
function siparis_sofor_migrate(PDO $pdo): array
{
    $rapor = [];
    if (!siparis_tablo_var($pdo, 'siparis_sevkiyatlar')) {
        return [['adim' => 'siparis_sevkiyatlar', 'durum' => 'hata', 'mesaj' => 'Önce Sipariş tablolarını oluşturun.']];
    }
    foreach (siparis_sofor_kolonlari() as [$kolon, $tanim]) {
        try {
            $pdo->query("SELECT `$kolon` FROM siparis_sevkiyatlar LIMIT 0");
            $rapor[] = ['adim' => "siparis_sevkiyatlar.$kolon", 'durum' => 'var', 'mesaj' => 'Kolon zaten mevcut.'];
            continue;
        } catch (PDOException $e) { /* yok → ekle */ }
        try {
            $pdo->exec("ALTER TABLE `siparis_sevkiyatlar` ADD COLUMN `$kolon` $tanim");
            $rapor[] = ['adim' => "siparis_sevkiyatlar.$kolon", 'durum' => 'eklendi', 'mesaj' => 'Kolon eklendi.'];
        } catch (PDOException $e) {
            error_log('[siparis_sofor_migrate] ' . $kolon . ': ' . $e->getMessage());
            $rapor[] = ['adim' => "siparis_sevkiyatlar.$kolon", 'durum' => 'hata', 'mesaj' => 'Kolon eklenemedi (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}
