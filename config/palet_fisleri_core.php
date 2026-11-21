<?php
// =========================================================
// config/palet_fisleri_core.php — Palet Fişleri şema yardımcıları (v535)
//
// loading_pallets'e EK kolonlar. YALNIZ migrate.php "Palet Fişleri" kartından çağrılır
// (açık GO ile); sayfa (palet_fisleri.php) kolon yoksa ilgili alanı pasif gösterir, ALTER ATMAZ.
//   aciklama1 / aciklama2  — açıklama alanları (v530)
//   mustahsil_id / mustahsil_ad — palet başına müstahsil (cari kartı + ünvan kopyası; v535)
// Yalnız ekleyici: mevcut veriye dokunmaz, yabancı anahtar YOK.
// =========================================================
declare(strict_types=1);

/** @return array<string,string> kolon adı → ALTER parçası */
function palet_fisleri_kolonlar(): array
{
    return [
        'aciklama1'    => 'ADD COLUMN `aciklama1` TEXT NULL',
        'aciklama2'    => 'ADD COLUMN `aciklama2` TEXT NULL',
        'mustahsil_id' => 'ADD COLUMN `mustahsil_id` INT NULL DEFAULT NULL',
        'mustahsil_ad' => 'ADD COLUMN `mustahsil_ad` VARCHAR(200) NULL DEFAULT NULL',
    ];
}

/** Önbelleksiz kolon denetimi (migrasyondan hemen sonra doğru durumu göstersin). */
function palet_fisleri_kolon_var(PDO $pdo, string $kolon): bool
{
    try {
        $kolonlar = $pdo->query('SHOW COLUMNS FROM `loading_pallets`')->fetchAll(PDO::FETCH_COLUMN);
        return in_array($kolon, $kolonlar, true);
    } catch (PDOException $e) {
        return false;
    }
}

function palet_fisleri_hazir(PDO $pdo): bool
{
    foreach (array_keys(palet_fisleri_kolonlar()) as $k) {
        if (!palet_fisleri_kolon_var($pdo, $k)) return false;
    }
    return true;
}

/**
 * Eksik kolonları ekler (idempotent). Yalnız migrate.php kartından çağrılır.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function palet_fisleri_migrate(PDO $pdo): array
{
    $rapor = [];
    try {
        $pdo->query('SELECT 1 FROM `loading_pallets` LIMIT 0');
    } catch (PDOException $e) {
        return [['tablo' => 'loading_pallets', 'durum' => 'hata', 'mesaj' => 'loading_pallets tablosu yok.']];
    }
    foreach (palet_fisleri_kolonlar() as $kolon => $parca) {
        if (palet_fisleri_kolon_var($pdo, $kolon)) { $rapor[] = ['tablo' => "loading_pallets.$kolon", 'durum' => 'var', 'mesaj' => 'Kolon zaten mevcut.']; continue; }
        try {
            $pdo->exec("ALTER TABLE `loading_pallets` $parca");
            $rapor[] = ['tablo' => "loading_pallets.$kolon", 'durum' => 'olusturuldu', 'mesaj' => 'Kolon eklendi.'];
        } catch (PDOException $e) {
            error_log('[palet_fisleri_migrate] ' . $kolon . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => "loading_pallets.$kolon", 'durum' => 'hata', 'mesaj' => 'Kolon eklenemedi (DB kullanıcısında ALTER yetkisi olmayabilir; ayrıntı sunucu günlüğünde).'];
        }
    }
    if (palet_fisleri_kolon_var($pdo, 'mustahsil_id')) {
        try {
            $var = false;
            foreach ($pdo->query('SHOW INDEX FROM `loading_pallets`')->fetchAll(PDO::FETCH_ASSOC) as $ix) {
                if (($ix['Key_name'] ?? '') === 'idx_lp_mustahsil') { $var = true; break; }
            }
            if (!$var) {
                $pdo->exec('ALTER TABLE `loading_pallets` ADD KEY `idx_lp_mustahsil` (`mustahsil_id`)');
                $rapor[] = ['tablo' => 'loading_pallets.idx_lp_mustahsil', 'durum' => 'olusturuldu', 'mesaj' => 'İndeks eklendi.'];
            }
        } catch (PDOException $e) {
            error_log('[palet_fisleri_migrate] idx: ' . $e->getMessage());
            $rapor[] = ['tablo' => 'loading_pallets.idx_lp_mustahsil', 'durum' => 'hata', 'mesaj' => 'İndeks eklenemedi (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}
