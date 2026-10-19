<?php
// =========================================================
// config/siparis_yurtici_gumruk.php — Sevkiyata elle girilen YURT İÇİ gümrük (v387)
// siparis_core.php sonunda yüklenir. Şema: yalnız migrate.php "Yurt içi gümrük" kartından (siparis_core.php'de ALTER YOK).
// PO'daki "Customs" (`gumruk`) YURT DIŞI gümrüktür (ör. çıkış sonrası sınır gümrüğü); bu alan ayrı ve yalnız elle girilir.
// =========================================================
declare(strict_types=1);

/** @return list<array{0:string,1:string}> [kolon, tanım] — tek nullable kolon (yalnız ADD COLUMN). */
function siparis_yurtici_gumruk_kolonlari(): array
{
    return [['yurtici_gumruk', 'VARCHAR(150) NULL DEFAULT NULL']];
}

/** Kolon var mı (yalnız olumlu sonuç önbelleğe alınır → migrasyondan sonra sayfa hemen görür). */
function siparis_yurtici_gumruk_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        $pdo->query('SELECT yurtici_gumruk FROM siparis_sevkiyatlar LIMIT 0');
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Eksik kolonu ekler (idempotent). @return list<array{adim:string,durum:string,mesaj:string}> durum: var|eklendi|hata */
function siparis_yurtici_gumruk_migrate(PDO $pdo): array
{
    if (!siparis_tablo_var($pdo, 'siparis_sevkiyatlar')) {
        return [['adim' => 'siparis_sevkiyatlar', 'durum' => 'hata', 'mesaj' => 'Önce Sipariş tablolarını oluşturun.']];
    }
    $rapor = [];
    foreach (siparis_yurtici_gumruk_kolonlari() as [$kolon, $tanim]) {
        try {
            $pdo->query("SELECT `$kolon` FROM siparis_sevkiyatlar LIMIT 0");
            $rapor[] = ['adim' => "siparis_sevkiyatlar.$kolon", 'durum' => 'var', 'mesaj' => 'Kolon zaten mevcut.'];
            continue;
        } catch (PDOException $e) { /* yok → ekle */ }
        try {
            $pdo->exec("ALTER TABLE `siparis_sevkiyatlar` ADD COLUMN `$kolon` $tanim");
            $rapor[] = ['adim' => "siparis_sevkiyatlar.$kolon", 'durum' => 'eklendi', 'mesaj' => 'Kolon eklendi.'];
        } catch (PDOException $e) {
            error_log('[siparis_yurtici_gumruk_migrate] ' . $kolon . ': ' . $e->getMessage());
            $rapor[] = ['adim' => "siparis_sevkiyatlar.$kolon", 'durum' => 'hata', 'mesaj' => 'Kolon eklenemedi (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}
