<?php
// =========================================================
// config/kullanici_tercih.php — Kullanıcıya özel arayüz tercihleri (v537)
//
// Sütun genişliği/sırası gibi tercihler KULLANICI BAZINDA sunucuda saklanır (hangi cihazdan girilirse aynı gelir).
// Şema: kullanici_tercihleri(user_id, anahtar, deger JSON) — YALNIZ migrate.php "Kullanıcı Tercihleri" kartından kurulur
// (CREATE TABLE IF NOT EXISTS); tablo yoksa sayfalar tarayıcıya (localStorage) düşer, hiçbir şey bozulmaz.
// Anahtarlar beyaz listededir; her anahtarın değeri kendi doğrulayıcısından geçer (kt_dogrula).
// =========================================================
declare(strict_types=1);

const KT_DEGER_MAX_BAYT = 4000;

function kt_ddl(): string
{
    return "CREATE TABLE IF NOT EXISTS `kullanici_tercihleri` (
  `user_id`    INT          NOT NULL,
  `anahtar`    VARCHAR(80)  NOT NULL,
  `deger`      TEXT         NOT NULL,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`user_id`, `anahtar`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

function kt_hazir(PDO $pdo): bool
{
    try { $pdo->query('SELECT 1 FROM `kullanici_tercihleri` LIMIT 0'); return true; }
    catch (PDOException $e) { return false; }
}

/** Yalnız migrate.php kartından çağrılır. @return list<array{tablo:string,durum:string,mesaj:string}> */
function kt_migrate(PDO $pdo): array
{
    if (kt_hazir($pdo)) return [['tablo' => 'kullanici_tercihleri', 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']];
    try {
        $pdo->exec(kt_ddl());
        return [['tablo' => 'kullanici_tercihleri', 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']];
    } catch (PDOException $e) {
        error_log('[kt_migrate] ' . $e->getMessage());
        return [['tablo' => 'kullanici_tercihleri', 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).']];
    }
}

/** Anahtar → doğrulayıcı. Değer geçerliyse temizlenmiş dizi, değilse null. */
function kt_dogrula(string $anahtar, mixed $deger): ?array
{
    if ($anahtar === 'palet_fisleri.tablo') {
        $kolonlar = ['belge_no', 'parti_no', 'palet_adi', 'firma', 'kasa_adi', 'kasa_adeti', 'brut_kg', 'dara_kg', 'net_kg', 'mustahsil_ad', 'aciklama1', 'aciklama2', 'tarih', 'islem', 'islem_zaman', 'kullanici', 'sube'];
        if (!is_array($deger)) return null;
        $out = ['genislik' => [], 'sira' => []];
        foreach ((array)($deger['genislik'] ?? []) as $k => $w) {
            if (in_array($k, $kolonlar, true) && is_numeric($w)) $out['genislik'][$k] = max(30, min(1200, (int)round((float)$w)));
        }
        foreach ((array)($deger['sira'] ?? []) as $k) {
            if (is_string($k) && in_array($k, $kolonlar, true) && !in_array($k, $out['sira'], true)) $out['sira'][] = $k;
        }
        return $out;
    }
    return null;
}

function kt_oku(PDO $pdo, int $uid, string $anahtar): ?array
{
    if ($uid <= 0 || !kt_hazir($pdo)) return null;
    try {
        $st = $pdo->prepare('SELECT deger FROM kullanici_tercihleri WHERE user_id = ? AND anahtar = ?');
        $st->execute([$uid, $anahtar]);
        $ham = $st->fetchColumn();
        if ($ham === false) return null;
        $j = json_decode((string)$ham, true);
        return kt_dogrula($anahtar, $j);
    } catch (PDOException $e) {
        return null;
    }
}

/** @return bool başarılı mı */
function kt_yaz(PDO $pdo, int $uid, string $anahtar, mixed $deger): bool
{
    if ($uid <= 0 || !kt_hazir($pdo)) return false;
    $temiz = kt_dogrula($anahtar, $deger);
    if ($temiz === null) return false;
    $json = json_encode($temiz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > KT_DEGER_MAX_BAYT) return false;
    $simdi = date('Y-m-d H:i:s');
    try {
        $g = $pdo->prepare('UPDATE kullanici_tercihleri SET deger = ?, updated_at = ? WHERE user_id = ? AND anahtar = ?');
        $g->execute([$json, $simdi, $uid, $anahtar]);
        if ($g->rowCount() === 0) {
            $var = $pdo->prepare('SELECT 1 FROM kullanici_tercihleri WHERE user_id = ? AND anahtar = ?');
            $var->execute([$uid, $anahtar]);
            if ($var->fetchColumn() === false) {
                $pdo->prepare('INSERT INTO kullanici_tercihleri (user_id, anahtar, deger, updated_at) VALUES (?, ?, ?, ?)')->execute([$uid, $anahtar, $json, $simdi]);
            }
        }
        return true;
    } catch (PDOException $e) {
        error_log('[kt_yaz] ' . $e->getMessage());
        return false;
    }
}
