<?php
// =========================================================
// config/kantar_alanlari_core.php — Mal Kabul Fişi ek alanları (v548)
//
// kantar_fisleri'ne EK kolonlar. YALNIZ migrate.php "Mal Kabul Alanları" kartından çağrılır (açık GO ile);
// sayfalar (kantar_create/edit/kantar.php/kantar_view) kolon yoksa ilgili alanı pasif/gizli gösterir, ALTER ATMAZ.
//   sofor      — şoför (Genel Bilgiler)
//   mal_sahibi — malın sahibi (Malzeme ve Depo)
//   mustahsil  — müstahsil (Malzeme ve Depo; serbest ad)
//   bahce      — bahçe (Malzeme ve Depo)
// Yalnız ekleyici: mevcut veriye dokunmaz, yabancı anahtar YOK.
// =========================================================
declare(strict_types=1);

/** @return array<string,string> kolon adı → ALTER parçası */
function kantar_alanlari_kolonlar(): array
{
    return [
        'sofor'      => "ADD COLUMN `sofor` VARCHAR(150) NOT NULL DEFAULT ''",
        'mal_sahibi' => "ADD COLUMN `mal_sahibi` VARCHAR(150) NOT NULL DEFAULT ''",
        'mustahsil'  => "ADD COLUMN `mustahsil` VARCHAR(150) NOT NULL DEFAULT ''",
        'bahce'      => "ADD COLUMN `bahce` VARCHAR(150) NOT NULL DEFAULT ''",
    ];
}

/** Mevcut kolon adları (istek başına bir kez okunur; migrasyon sonrası sıfırlanabilir). */
function kantar_alanlari_mevcut(PDO $pdo, bool $yenile = false): array
{
    static $onbellek = null;
    if ($onbellek === null || $yenile) {
        try { $onbellek = $pdo->query('SHOW COLUMNS FROM `kantar_fisleri`')->fetchAll(PDO::FETCH_COLUMN); }
        catch (PDOException $e) { $onbellek = []; }
    }
    return $onbellek;
}

/** @return array<string,bool> kolon adı → var mı */
function kantar_alanlari_durum(PDO $pdo): array
{
    $mevcut = kantar_alanlari_mevcut($pdo);
    $d = [];
    foreach (array_keys(kantar_alanlari_kolonlar()) as $k) $d[$k] = in_array($k, $mevcut, true);
    return $d;
}

function kantar_alanlari_hazir(PDO $pdo): bool
{
    return !in_array(false, kantar_alanlari_durum($pdo), true);
}

/**
 * Formdan gelen ek alanları fişe yazar. Yalnız VAR olan kolonlar güncellenir (kolon yoksa sessizce atlanır).
 * Değerler TR-büyük harfe çevrilir, kolon uzunluğuna (150) göre kırpılır.
 * @param array<string,mixed> $post
 */
function kantar_alanlari_yaz(PDO $pdo, int $fis_id, array $post): void
{
    $durum = kantar_alanlari_durum($pdo);
    $set = []; $par = [];
    foreach ($durum as $kolon => $var) {
        if (!$var || !array_key_exists($kolon, $post)) continue;
        $v = trim((string)preg_replace('/\s+/u', ' ', (string)$post[$kolon]));
        $v = $v === '' ? '' : mb_substr(tr_upper($v), 0, 150, 'UTF-8');
        $set[] = "`$kolon` = ?"; $par[] = $v;
    }
    if (!$set) return;
    $par[] = $fis_id;
    $pdo->prepare('UPDATE `kantar_fisleri` SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($par);
}

/**
 * Eksik kolonları ekler (idempotent). Yalnız migrate.php kartından çağrılır.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function kantar_alanlari_migrate(PDO $pdo): array
{
    try { $pdo->query('SELECT 1 FROM `kantar_fisleri` LIMIT 0'); }
    catch (PDOException $e) { return [['tablo' => 'kantar_fisleri', 'durum' => 'hata', 'mesaj' => 'kantar_fisleri tablosu yok.']]; }
    $rapor = [];
    $mevcut = kantar_alanlari_mevcut($pdo, true);
    foreach (kantar_alanlari_kolonlar() as $kolon => $parca) {
        if (in_array($kolon, $mevcut, true)) { $rapor[] = ['tablo' => "kantar_fisleri.$kolon", 'durum' => 'var', 'mesaj' => 'Kolon zaten mevcut.']; continue; }
        try {
            $pdo->exec("ALTER TABLE `kantar_fisleri` $parca");
            $rapor[] = ['tablo' => "kantar_fisleri.$kolon", 'durum' => 'olusturuldu', 'mesaj' => 'Kolon eklendi.'];
        } catch (PDOException $e) {
            error_log('[kantar_alanlari_migrate] ' . $kolon . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => "kantar_fisleri.$kolon", 'durum' => 'hata', 'mesaj' => 'Kolon eklenemedi (DB kullanıcısında ALTER yetkisi olmayabilir; ayrıntı sunucu günlüğünde).'];
        }
    }
    kantar_alanlari_mevcut($pdo, true);
    return $rapor;
}
