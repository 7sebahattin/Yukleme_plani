<?php
declare(strict_types=1);
// =========================================================
// config/ajanda_core.php — Ajanda: kullanıcıya özel görev / hatırlatma defteri (v574)
//
// Şema: ajanda_kayitlar — YALNIZ migrate.php "Ajanda" kartından kurulur (CREATE TABLE IF NOT EXISTS, ekleyici).
// Tablo yoksa sayfa kurulum notu gösterir, hiçbir şey bozulmaz (kullanici_tercihleri deseni).
//
// DEĞİŞMEZLER:
//  - KİŞİSEL: her sorgu `user_id = ?` taşır; yönetici dahil hiç kimse başkasının ajandasını görmez/değiştirmez.
//    user_id çağıranın (current_user()) verdiği id'dir, istemciden ALINMAZ.
//  - Depo filtresi YOK (kişisel kayıt; Hesap modülüyle aynı bilinçli istisna).
//  - audit'e yalnız olay + kayıt id'si yazılır; başlık/not içeriği YAZILMAZ (özel notlar yönetici ekranına sızmasın).
//  - Fonksiyonlar çıktı basmaz, exit etmez; SQL taşınabilir (NOW() yok, zamanı PHP verir) → SQLite'ta test edilir.
// =========================================================

const AJ_BASLIK_MAX = 160;
const AJ_NOT_MAX = 2000;
const AJ_ACIK_MAX = 500;      // kullanıcı başına açık (tamamlanmamış) kayıt tavanı
const AJ_TAMAM_LISTE = 50;    // "Tamamlanan" görünümünde en son kaç kayıt

function ajanda_ddl(): string
{
    return "CREATE TABLE IF NOT EXISTS `ajanda_kayitlar` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT          NOT NULL,
  `baslik`      VARCHAR(160) NOT NULL,
  `not_metni`   TEXT         NULL,
  `tarih`       DATE         NOT NULL,
  `saat`        VARCHAR(5)   NULL,
  `tamam`       TINYINT(1)   NOT NULL DEFAULT 0,
  `tamam_zaman` DATETIME     NULL,
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  KEY `idx_ajanda_kullanici` (`user_id`, `tamam`, `tarih`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

function ajanda_hazir(PDO $pdo): bool
{
    try { $pdo->query('SELECT 1 FROM `ajanda_kayitlar` LIMIT 0'); return true; }
    catch (PDOException $e) { return false; }
}

/** Yalnız migrate.php kartından çağrılır. @return list<array{tablo:string,durum:string,mesaj:string}> */
function ajanda_migrate(PDO $pdo): array
{
    if (ajanda_hazir($pdo)) return [['tablo' => 'ajanda_kayitlar', 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']];
    try {
        $ddl = $GLOBALS['_ajanda_ddl_calistir'] ?? null;   // test kancası (SQLite çevirisi)
        if (is_callable($ddl)) $ddl($pdo, ajanda_ddl()); else $pdo->exec(ajanda_ddl());
        return [['tablo' => 'ajanda_kayitlar', 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']];
    } catch (PDOException $e) {
        error_log('[ajanda_migrate] ' . $e->getMessage());
        return [['tablo' => 'ajanda_kayitlar', 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).']];
    }
}

/** 'YYYY-MM-DD' kesin biçim + gerçek takvim günü; değilse null. */
function ajanda_tarih_coz(string $s): ?string
{
    $s = trim($s);
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    $e = DateTime::getLastErrors();
    if ($d === false || ($e && ($e['warning_count'] > 0 || $e['error_count'] > 0)) || $d->format('Y-m-d') !== $s) return null;
    $yil = (int)$d->format('Y');
    return ($yil >= 2000 && $yil <= 2100) ? $s : null;
}

/** Boş → '' (saatsiz); geçerli 'H:i' → 'HH:MM'; geçersiz → null. */
function ajanda_saat_coz(string $s): ?string
{
    $s = trim($s);
    if ($s === '') return '';
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $s, $m)) return null;
    return sprintf('%02d:%s', (int)$m[1], $m[2]);
}

/**
 * Form girdisini doğrular/temizler.
 * @return array{ok:bool, mesaj?:string, veri?:array{baslik:string,not_metni:string,tarih:string,saat:string}}
 */
function ajanda_girdi_coz(array $giris): array
{
    $baslik = trim((string)preg_replace('/\s+/u', ' ', (string)($giris['baslik'] ?? '')));
    if ($baslik === '') return ['ok' => false, 'mesaj' => 'Başlık boş olamaz.'];
    if (mb_strlen($baslik, 'UTF-8') > AJ_BASLIK_MAX) return ['ok' => false, 'mesaj' => 'Başlık en fazla ' . AJ_BASLIK_MAX . ' karakter olabilir.'];
    $not = trim(str_replace("\r\n", "\n", (string)($giris['not_metni'] ?? '')));
    $not = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $not);
    if (mb_strlen($not, 'UTF-8') > AJ_NOT_MAX) return ['ok' => false, 'mesaj' => 'Not en fazla ' . AJ_NOT_MAX . ' karakter olabilir.'];
    $tarih = ajanda_tarih_coz((string)($giris['tarih'] ?? ''));
    if ($tarih === null) return ['ok' => false, 'mesaj' => 'Geçerli bir tarih seçin.'];
    $saat = ajanda_saat_coz((string)($giris['saat'] ?? ''));
    if ($saat === null) return ['ok' => false, 'mesaj' => 'Saat SS:DD biçiminde olmalı (ör. 14:30).'];
    return ['ok' => true, 'veri' => ['baslik' => $baslik, 'not_metni' => $not, 'tarih' => $tarih, 'saat' => $saat]];
}

function ajanda_audit(string $olay, int $uid, int $id): void
{
    try { if (function_exists('audit_log_event')) audit_log_event($olay, 'ajanda', $id, null, null, $uid); }
    catch (Throwable $e) { error_log('[ajanda] audit yazılamadı: ' . get_class($e)); }
}

/** @return array{ok:bool, mesaj:string, id?:int} */
function ajanda_ekle(PDO $pdo, int $uid, array $giris, ?string $simdi = null): array
{
    if ($uid < 1) return ['ok' => false, 'mesaj' => 'Oturum bulunamadı.'];
    $r = ajanda_girdi_coz($giris);
    if (!$r['ok']) return ['ok' => false, 'mesaj' => $r['mesaj']];
    $v = $r['veri'];
    $simdi = $simdi ?? date('Y-m-d H:i:s');
    try {
        $c = $pdo->prepare('SELECT COUNT(*) FROM ajanda_kayitlar WHERE user_id = ? AND tamam = 0');
        $c->execute([$uid]);
        if ((int)$c->fetchColumn() >= AJ_ACIK_MAX) return ['ok' => false, 'mesaj' => 'En fazla ' . AJ_ACIK_MAX . ' açık kayıt tutulabilir; önce tamamlananları silin.'];
        $pdo->prepare('INSERT INTO ajanda_kayitlar (user_id, baslik, not_metni, tarih, saat, tamam, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?)')
            ->execute([$uid, $v['baslik'], $v['not_metni'] === '' ? null : $v['not_metni'], $v['tarih'], $v['saat'] === '' ? null : $v['saat'], $simdi, $simdi]);
        $id = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[ajanda_ekle] ' . get_class($e));
        return ['ok' => false, 'mesaj' => 'Kayıt eklenemedi (teknik hata). Tekrar deneyin.'];
    }
    ajanda_audit('create', $uid, $id);
    return ['ok' => true, 'mesaj' => 'Ajandaya eklendi.', 'id' => $id];
}

/** @return array{ok:bool, mesaj:string} */
function ajanda_guncelle(PDO $pdo, int $uid, int $id, array $giris, ?string $simdi = null): array
{
    if ($uid < 1 || $id < 1) return ['ok' => false, 'mesaj' => 'Kayıt bulunamadı.'];
    $r = ajanda_girdi_coz($giris);
    if (!$r['ok']) return ['ok' => false, 'mesaj' => $r['mesaj']];
    $v = $r['veri'];
    try {
        $g = $pdo->prepare('UPDATE ajanda_kayitlar SET baslik = ?, not_metni = ?, tarih = ?, saat = ?, updated_at = ? WHERE id = ? AND user_id = ?');
        $g->execute([$v['baslik'], $v['not_metni'] === '' ? null : $v['not_metni'], $v['tarih'], $v['saat'] === '' ? null : $v['saat'], $simdi ?? date('Y-m-d H:i:s'), $id, $uid]);
        if ($g->rowCount() === 0) {
            $var = $pdo->prepare('SELECT 1 FROM ajanda_kayitlar WHERE id = ? AND user_id = ?');
            $var->execute([$id, $uid]);
            if ($var->fetchColumn() === false) return ['ok' => false, 'mesaj' => 'Kayıt bulunamadı.'];   // başkasının kaydı da "bulunamadı"
        }
    } catch (Throwable $e) {
        error_log('[ajanda_guncelle] ' . get_class($e));
        return ['ok' => false, 'mesaj' => 'Kayıt güncellenemedi (teknik hata). Tekrar deneyin.'];
    }
    ajanda_audit('update', $uid, $id);
    return ['ok' => true, 'mesaj' => 'Kayıt güncellendi.'];
}

/** @return array{ok:bool, mesaj:string} */
function ajanda_durum(PDO $pdo, int $uid, int $id, bool $tamam, ?string $simdi = null): array
{
    if ($uid < 1 || $id < 1) return ['ok' => false, 'mesaj' => 'Kayıt bulunamadı.'];
    $simdi = $simdi ?? date('Y-m-d H:i:s');
    try {
        $g = $pdo->prepare('UPDATE ajanda_kayitlar SET tamam = ?, tamam_zaman = ?, updated_at = ? WHERE id = ? AND user_id = ?');
        $g->execute([$tamam ? 1 : 0, $tamam ? $simdi : null, $simdi, $id, $uid]);
        if ($g->rowCount() === 0) {
            $var = $pdo->prepare('SELECT 1 FROM ajanda_kayitlar WHERE id = ? AND user_id = ?');
            $var->execute([$id, $uid]);
            if ($var->fetchColumn() === false) return ['ok' => false, 'mesaj' => 'Kayıt bulunamadı.'];
        }
    } catch (Throwable $e) {
        error_log('[ajanda_durum] ' . get_class($e));
        return ['ok' => false, 'mesaj' => 'Durum değiştirilemedi (teknik hata). Tekrar deneyin.'];
    }
    return ['ok' => true, 'mesaj' => $tamam ? 'Tamamlandı olarak işaretlendi.' : 'Yeniden açıldı.'];
}

/** @return array{ok:bool, mesaj:string} */
function ajanda_sil(PDO $pdo, int $uid, int $id): array
{
    if ($uid < 1 || $id < 1) return ['ok' => false, 'mesaj' => 'Kayıt bulunamadı.'];
    try {
        $d = $pdo->prepare('DELETE FROM ajanda_kayitlar WHERE id = ? AND user_id = ?');
        $d->execute([$id, $uid]);
        if ($d->rowCount() === 0) return ['ok' => false, 'mesaj' => 'Kayıt bulunamadı.'];
    } catch (Throwable $e) {
        error_log('[ajanda_sil] ' . get_class($e));
        return ['ok' => false, 'mesaj' => 'Kayıt silinemedi (teknik hata). Tekrar deneyin.'];
    }
    ajanda_audit('delete', $uid, $id);
    return ['ok' => true, 'mesaj' => 'Kayıt silindi.'];
}

/** Tamamlananları toplu siler (yalnız bu kullanıcının). @return array{ok:bool, mesaj:string, adet:int} */
function ajanda_tamamlananlari_sil(PDO $pdo, int $uid): array
{
    if ($uid < 1) return ['ok' => false, 'mesaj' => 'Oturum bulunamadı.', 'adet' => 0];
    try {
        $d = $pdo->prepare('DELETE FROM ajanda_kayitlar WHERE user_id = ? AND tamam = 1');
        $d->execute([$uid]);
        $n = $d->rowCount();
    } catch (Throwable $e) {
        error_log('[ajanda_tamam_sil] ' . get_class($e));
        return ['ok' => false, 'mesaj' => 'Silinemedi (teknik hata). Tekrar deneyin.', 'adet' => 0];
    }
    if ($n > 0) ajanda_audit('delete_completed', $uid, 0);
    return ['ok' => true, 'mesaj' => $n > 0 ? "$n tamamlanan kayıt silindi." : 'Silinecek tamamlanan kayıt yok.', 'adet' => $n];
}

/**
 * @param string $gorunum 'acik' (varsayılan) | 'tamam' | 'tumu'
 * @return list<array<string,mixed>>
 */
function ajanda_listele(PDO $pdo, int $uid, string $gorunum = 'acik'): array
{
    if ($uid < 1) return [];
    try {
        if ($gorunum === 'tamam') {
            $st = $pdo->prepare('SELECT * FROM ajanda_kayitlar WHERE user_id = ? AND tamam = 1 ORDER BY tamam_zaman DESC, id DESC LIMIT ' . AJ_TAMAM_LISTE);
        } elseif ($gorunum === 'tumu') {
            $st = $pdo->prepare("SELECT * FROM ajanda_kayitlar WHERE user_id = ? ORDER BY tarih ASC, COALESCE(saat, '99:99') ASC, id ASC LIMIT 1000");
        } else {
            $st = $pdo->prepare("SELECT * FROM ajanda_kayitlar WHERE user_id = ? AND tamam = 0 ORDER BY tarih ASC, COALESCE(saat, '99:99') ASC, id ASC LIMIT " . AJ_ACIK_MAX);
        }
        $st->execute([$uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[ajanda_listele] ' . get_class($e));
        return [];
    }
}

/** Açık kayıtları bugüne göre kovalara ayırır. @return array<string, list<array<string,mixed>>> */
function ajanda_grupla(array $kayitlar, string $bugun): array
{
    $yarin = date('Y-m-d', strtotime($bugun . ' +1 day'));
    $hafta = date('Y-m-d', strtotime($bugun . ' +7 day'));
    $k = ['gecikmis' => [], 'bugun' => [], 'yarin' => [], 'hafta' => [], 'sonra' => []];
    foreach ($kayitlar as $r) {
        $t = (string)$r['tarih'];
        if ($t < $bugun) $k['gecikmis'][] = $r;
        elseif ($t === $bugun) $k['bugun'][] = $r;
        elseif ($t === $yarin) $k['yarin'][] = $r;
        elseif ($t <= $hafta) $k['hafta'][] = $r;
        else $k['sonra'][] = $r;
    }
    return $k;
}

/** @return array{gecikmis:int,bugun:int,hafta:int,acik:int,tamam:int} */
function ajanda_ozet(PDO $pdo, int $uid, string $bugun): array
{
    $o = ['gecikmis' => 0, 'bugun' => 0, 'hafta' => 0, 'acik' => 0, 'tamam' => 0];
    if ($uid < 1) return $o;
    $hafta = date('Y-m-d', strtotime($bugun . ' +7 day'));
    try {
        $st = $pdo->prepare('SELECT
              SUM(CASE WHEN tamam = 0 AND tarih < ? THEN 1 ELSE 0 END) AS gecikmis,
              SUM(CASE WHEN tamam = 0 AND tarih = ? THEN 1 ELSE 0 END) AS bugun,
              SUM(CASE WHEN tamam = 0 AND tarih > ? AND tarih <= ? THEN 1 ELSE 0 END) AS hafta,
              SUM(CASE WHEN tamam = 0 THEN 1 ELSE 0 END) AS acik,
              SUM(CASE WHEN tamam = 1 THEN 1 ELSE 0 END) AS tamam
            FROM ajanda_kayitlar WHERE user_id = ?');
        $st->execute([$bugun, $bugun, $bugun, $hafta, $uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($o as $k => $_) $o[$k] = (int)($r[$k] ?? 0);
    } catch (Throwable $e) {
        error_log('[ajanda_ozet] ' . get_class($e));
    }
    return $o;
}

/** 'YYYY-MM-DD' → "24 Kas 2026 Salı". */
function ajanda_tarih_etiket(string $tarih): string
{
    $ts = strtotime($tarih . ' 12:00:00');
    if ($ts === false) return $tarih;
    static $ay = [1 => 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
    static $gun = [1 => 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
    return (int)date('j', $ts) . ' ' . $ay[(int)date('n', $ts)] . ' ' . date('Y', $ts) . ' ' . $gun[(int)date('N', $ts)];
}
