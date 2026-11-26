<?php
// =========================================================
// config/crm_core.php — ERP-8 CRM (Pipeline, Aktivite, Fiyat Listeleri, Teklif→Sipariş, Kalite Claim)
// + ERP Faz 4 ortak yardımcıları (erp_plan_*). Include-only (404). Sıfır runtime DDL.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('db_tx')) {
    require_once __DIR__ . '/db_tx_helpers.php';
}

// ── ERP Faz 4 Ortak Yetki / Yanıt / Sayı Yardımcıları ──────────────────────

function erp_plan_can(string $eylem = 'read'): bool
{
    if (function_exists('is_admin') && is_admin()) return true;
    if ($eylem === 'read') {
        return function_exists('muhasebe_grup_okuma_can') && muhasebe_grup_okuma_can('erp_planlama');
    }
    return function_exists('muhasebe_islem_can') && muhasebe_islem_can('erp_planlama', $eylem);
}

function erp_plan_kapi(string $eylem = 'read'): void
{
    if (!erp_plan_can($eylem)) {
        if (function_exists('forbidden')) forbidden();
        http_response_code(403);
        exit('Bu işlem için yetkiniz yok.');
    }
}

function erp_plan_no_store(): void
{
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }
}

function erp_plan_json_istek(): bool
{
    return (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
}

function erp_plan_geri_guvenli(string $geri, string $varsayilan = 'crm_yonetim.php'): string
{
    $g = trim($geri);
    if ($g === '' || str_contains($g, '://') || str_starts_with($g, '//') || str_contains($g, "\n") || str_contains($g, "\r")) {
        return $varsayilan;
    }
    return preg_match('/^[a-zA-Z0-9_.-]+\.php(?:\?[a-zA-Z0-9_=&%-]*)?$/', $g) ? $g : $varsayilan;
}

/** @param array<string,mixed> $sonuc */
function erp_plan_cevap(array $sonuc, string $geri, string $basariMesaj, bool $json): never
{
    $ok = !empty($sonuc['ok']);
    $kod = (int)($sonuc['kod'] ?? ($ok ? 200 : 422));
    if ($json) {
        http_response_code($kod);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('flash_set')) {
        flash_set($ok ? 'success' : 'error', (string)($ok ? ($sonuc['mesaj'] ?? $basariMesaj) : ($sonuc['hata'] ?? 'İşlem başarısız.')));
    }
    header('Location: ' . $geri);
    exit;
}

function erp_plan_tarih_gecerli(string $t): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function erp_plan_sayi($v): float
{
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = trim((string)($v ?? ''));
    if ($s === '') return 0.0;
    $s = str_replace(["\xc2\xa0", ' '], '', $s);
    if (str_contains($s, ',') && str_contains($s, '.')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (str_contains($s, ',')) {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float)$s : 0.0;
}

// ── ERP-8 CRM Tabloları & Migrasyon ────────────────────────────────────────

/** @return list<string> */
function crm_tablolar(): array
{
    return ['crm_firsatlar', 'crm_aktiviteler', 'crm_fiyat_listeleri', 'crm_fiyat_satirlar', 'crm_teklifler', 'crm_claimler'];
}

/** @return list<string> */
function crm_eksik_tablolar(PDO $pdo): array
{
    $eksik = [];
    foreach (crm_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $eksik[] = $t;
    }
    return $eksik;
}

function crm_hazir(PDO $pdo): bool
{
    return crm_eksik_tablolar($pdo) === [];
}

function crm_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = crm_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'CRM şeması kurulu değil: ' . implode(', ', $eksik)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header')) render_header('CRM & Fiyat Listeleri');
    if (function_exists('erp_plan_head_varlik')) erp_plan_head_varlik();
    if (function_exists('erp_plan_sema_uyari')) {
        echo erp_plan_sema_uyari($eksik, 'crm-fiyat', 'CRM & Fiyat Listeleri (ERP-8)');
    }
    if (function_exists('render_footer')) render_footer();
    exit;
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function crm_migrate(PDO $pdo): array
{
    $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $ai  = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'crm_firsatlar' => "CREATE TABLE IF NOT EXISTS `crm_firsatlar` (
            `id` $ai,
            `kod` VARCHAR(40) NOT NULL UNIQUE,
            `cari_id` INT NULL,
            `musteri_ad` VARCHAR(160) NOT NULL,
            `baslik` VARCHAR(200) NOT NULL,
            `asama` VARCHAR(20) NOT NULL DEFAULT 'aday',
            `tahmini_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `olasilik_yuzde` INT NOT NULL DEFAULT 20,
            `kapanis_hedef` DATE NULL,
            `sorumlu_id` INT NULL,
            `notlar` VARCHAR(500) NOT NULL DEFAULT '',
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'crm_aktiviteler' => "CREATE TABLE IF NOT EXISTS `crm_aktiviteler` (
            `id` $ai,
            `firsat_id` INT NOT NULL,
            `tarih` DATE NOT NULL,
            `tur` VARCHAR(20) NOT NULL DEFAULT 'gorusme',
            `ozet` VARCHAR(400) NOT NULL,
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'crm_fiyat_listeleri' => "CREATE TABLE IF NOT EXISTS `crm_fiyat_listeleri` (
            `id` $ai,
            `kod` VARCHAR(40) NOT NULL UNIQUE,
            `ad` VARCHAR(160) NOT NULL,
            `musteri_grubu` VARCHAR(80) NOT NULL DEFAULT 'GENEL',
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `bas_tarih` DATE NOT NULL,
            `bit_tarih` DATE NULL,
            `varsayilan_iskonto` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `aktif` TINYINT(1) NOT NULL DEFAULT 1,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'crm_fiyat_satirlar' => "CREATE TABLE IF NOT EXISTS `crm_fiyat_satirlar` (
            `id` $ai,
            `liste_id` INT NOT NULL,
            `stok_id` INT NULL,
            `urun_ad` VARCHAR(160) NOT NULL,
            `birim` VARCHAR(20) NOT NULL DEFAULT 'kg',
            `birim_fiyat` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
            `min_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `iskonto_yuzde` DECIMAL(6,2) NOT NULL DEFAULT 0.00
        )$eng",
        'crm_teklifler' => "CREATE TABLE IF NOT EXISTS `crm_teklifler` (
            `id` $ai,
            `teklif_no` VARCHAR(40) NOT NULL UNIQUE,
            `firsat_id` INT NULL,
            `fiyat_liste_id` INT NULL,
            `cari_id` INT NULL,
            `musteri_ad` VARCHAR(160) NOT NULL,
            `tarih` DATE NOT NULL,
            `gecerlilik_tarih` DATE NULL,
            `urun_ozet` VARCHAR(240) NOT NULL,
            `miktar` DECIMAL(14,3) NOT NULL DEFAULT 1.000,
            `birim_fiyat` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
            `iskonto_yuzde` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `ara_toplam` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `kdv_orani` DECIMAL(6,2) NOT NULL DEFAULT 20.00,
            `genel_toplam` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `durum` VARCHAR(24) NOT NULL DEFAULT 'taslak',
            `siparis_ref` VARCHAR(60) NULL,
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'crm_claimler' => "CREATE TABLE IF NOT EXISTS `crm_claimler` (
            `id` $ai,
            `claim_no` VARCHAR(40) NOT NULL UNIQUE,
            `cari_id` INT NULL,
            `musteri_ad` VARCHAR(160) NOT NULL,
            `sevkiyat_ref` VARCHAR(80) NOT NULL DEFAULT '',
            `tarih` DATE NOT NULL,
            `kategori` VARCHAR(30) NOT NULL DEFAULT 'kalite',
            `talep_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `kabul_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `durum` VARCHAR(20) NOT NULL DEFAULT 'acik',
            `aciklama` VARCHAR(500) NOT NULL DEFAULT '',
            `cozum_notu` VARCHAR(500) NOT NULL DEFAULT '',
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
    ];

    $sonuc = [];
    foreach ($ddl as $tablo => $sql) {
        $var = db_table_exists($pdo, $tablo);
        if (!$var) $pdo->exec($sql);
        $sonuc[] = ['tablo' => $tablo, 'durum' => $var ? 'mevcut' : 'olusturuldu', 'mesaj' => $var ? 'Tablo zaten mevcut.' : 'Tablo oluşturuldu.'];
    }
    return $sonuc;
}

/** @return array<string,string> */
function crm_asamalari(): array
{
    return [
        'aday'       => 'Aday (Lead)',
        'gorusme'    => 'İlk Görüşme',
        'teklif'     => 'Teklif Verildi',
        'muzakere'   => 'Müzakere',
        'kazanildi'  => 'Kazanıldı',
        'kaybedildi' => 'Kaybedildi',
    ];
}

// ── 1) Satış Fırsatı (Pipeline) & Aktivite ─────────────────────────────────

/** @return array{ok:bool,id?:int,kod?:string,hata?:string,mesaj?:string,kod_http?:int,kod?:int} */
function crm_firsat_kaydet(PDO $pdo, array $g, int $uid): array
{
    $musteri = mb_substr(trim((string)($g['musteri_ad'] ?? '')), 0, 160, 'UTF-8');
    $baslik  = mb_substr(trim((string)($g['baslik'] ?? '')), 0, 200, 'UTF-8');
    $asama   = (string)($g['asama'] ?? 'aday');
    if (!isset(crm_asamalari()[$asama])) $asama = 'aday';
    $tutar   = max(0.0, round(erp_plan_sayi($g['tahmini_tutar'] ?? 0), 2));
    $pb      = strtoupper(trim((string)($g['para_birimi'] ?? 'TRY')));
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';
    $olas    = max(0, min(100, (int)($g['olasilik_yuzde'] ?? ($asama === 'kazanildi' ? 100 : ($asama === 'kaybedildi' ? 0 : 30)))));
    $hedef   = trim((string)($g['kapanis_hedef'] ?? ''));
    if ($hedef !== '' && !erp_plan_tarih_gecerli($hedef)) $hedef = '';
    $cariId  = isset($g['cari_id']) && (int)$g['cari_id'] > 0 ? (int)$g['cari_id'] : null;
    $notlar  = mb_substr(trim((string)($g['notlar'] ?? '')), 0, 500, 'UTF-8');

    if ($musteri === '' || $baslik === '') {
        return ['ok' => false, 'hata' => 'Müşteri adı ve fırsat başlığı zorunludur.', 'kod' => 422];
    }

    return db_tx($pdo, static function (PDO $p) use ($cariId, $musteri, $baslik, $asama, $tutar, $pb, $olas, $hedef, $notlar, $uid): array {
        $n = (int)$p->query('SELECT COUNT(*) FROM `crm_firsatlar`')->fetchColumn() + 1;
        $kod = sprintf('CRM-%s-%04d', date('Y'), $n);
        while (true) {
            $c = $p->prepare('SELECT 1 FROM `crm_firsatlar` WHERE kod = ?');
            $c->execute([$kod]);
            if (!$c->fetchColumn()) break;
            $n++;
            $kod = sprintf('CRM-%s-%04d', date('Y'), $n);
        }
        $p->prepare(
            'INSERT INTO `crm_firsatlar` (kod, cari_id, musteri_ad, baslik, asama, tahmini_tutar, para_birimi, olasilik_yuzde, kapanis_hedef, sorumlu_id, notlar)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$kod, $cariId, $musteri, $baslik, $asama, $tutar, $pb, $olas, $hedef !== '' ? $hedef : null, $uid ?: null, $notlar]);
        $id = (int)$p->lastInsertId();
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'crm_firsat', $id, null, ['kod' => $kod, 'musteri' => $musteri, 'tutar' => $tutar]);
        }
        return ['ok' => true, 'id' => $id, 'firsat_kod' => $kod, 'mesaj' => "Satış fırsatı oluşturuldu ($kod)."];
    });
}

/** @return array{ok:bool,hata?:string,mesaj?:string,kod?:int} */
function crm_firsat_asama_guncelle(PDO $pdo, int $firsatId, string $yeniAsama, int $uid): array
{
    if (!isset(crm_asamalari()[$yeniAsama])) return ['ok' => false, 'hata' => 'Geçersiz fırsat aşaması.', 'kod' => 422];
    $st = $pdo->prepare('SELECT asama, kod FROM `crm_firsatlar` WHERE id = ?');
    $st->execute([$firsatId]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) return ['ok' => false, 'hata' => 'Fırsat bulunamadı.', 'kod' => 404];
    $olasMap = ['aday' => 15, 'gorusme' => 30, 'teklif' => 55, 'muzakere' => 75, 'kazanildi' => 100, 'kaybedildi' => 0];
    $pdo->prepare('UPDATE `crm_firsatlar` SET asama=?, olasilik_yuzde=? WHERE id=?')->execute([$yeniAsama, $olasMap[$yeniAsama], $firsatId]);
    if (function_exists('audit_log_event')) {
        audit_log_event('update', 'crm_firsat', $firsatId, ['asama' => $f['asama']], ['asama' => $yeniAsama, 'uid' => $uid]);
    }
    return ['ok' => true, 'mesaj' => $f['kod'] . ' aşaması güncellendi (' . crm_asamalari()[$yeniAsama] . ').'];
}

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function crm_aktivite_ekle(PDO $pdo, array $g, int $uid): array
{
    $fid   = (int)($g['firsat_id'] ?? 0);
    $tarih = trim((string)($g['tarih'] ?? date('Y-m-d')));
    $tur   = (string)($g['tur'] ?? 'gorusme');
    if (!in_array($tur, ['gorusme', 'telefon', 'eposta', 'ziyaret', 'teklif'], true)) $tur = 'gorusme';
    $ozet  = mb_substr(trim((string)($g['ozet'] ?? '')), 0, 400, 'UTF-8');
    if ($fid <= 0 || $ozet === '' || !erp_plan_tarih_gecerli($tarih)) {
        return ['ok' => false, 'hata' => 'Fırsat, tarih ve aktivite özeti zorunludur.', 'kod' => 422];
    }
    $pdo->prepare('INSERT INTO `crm_aktiviteler` (firsat_id, tarih, tur, ozet, olusturan_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$fid, $tarih, $tur, $ozet, $uid ?: null]);
    return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'mesaj' => 'CRM aktivitesi kaydedildi.'];
}

// ── 2) Fiyat Listeleri & Dinamik Fiyat Çözücü ──────────────────────────────

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function crm_fiyat_listesi_kaydet(PDO $pdo, array $g, int $uid): array
{
    $kod   = mb_strtoupper(mb_substr(trim((string)($g['kod'] ?? '')), 0, 40, 'UTF-8'), 'UTF-8');
    $ad    = mb_substr(trim((string)($g['ad'] ?? '')), 0, 160, 'UTF-8');
    $grup  = mb_strtoupper(mb_substr(trim((string)($g['musteri_grubu'] ?? 'GENEL')), 0, 80, 'UTF-8'), 'UTF-8');
    $pb    = strtoupper(trim((string)($g['para_birimi'] ?? 'TRY')));
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';
    $bas   = trim((string)($g['bas_tarih'] ?? date('Y-m-d')));
    $bit   = trim((string)($g['bit_tarih'] ?? ''));
    $isk   = max(0.0, min(80.0, round(erp_plan_sayi($g['varsayilan_iskonto'] ?? 0), 2)));

    if ($ad === '') return ['ok' => false, 'hata' => 'Fiyat listesi adı zorunludur.', 'kod' => 422];
    if (!erp_plan_tarih_gecerli($bas)) return ['ok' => false, 'hata' => 'Geçersiz başlangıç tarihi.', 'kod' => 422];
    if ($bit !== '' && (!erp_plan_tarih_gecerli($bit) || $bit < $bas)) return ['ok' => false, 'hata' => 'Geçersiz bitiş tarihi.', 'kod' => 422];
    if ($kod === '') $kod = 'FYT-' . date('ym') . '-' . random_int(100, 999);

    return db_tx($pdo, static function (PDO $p) use ($kod, $ad, $grup, $pb, $bas, $bit, $isk, $uid): array {
        $chk = $p->prepare('SELECT 1 FROM `crm_fiyat_listeleri` WHERE kod = ?');
        $chk->execute([$kod]);
        if ($chk->fetchColumn()) return ['ok' => false, 'hata' => "Bu fiyat listesi kodu zaten mevcut ($kod).", 'kod' => 409];

        $p->prepare(
            'INSERT INTO `crm_fiyat_listeleri` (kod, ad, musteri_grubu, para_birimi, bas_tarih, bit_tarih, varsayilan_iskonto, aktif) VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([$kod, $ad, $grup, $pb, $bas, $bit !== '' ? $bit : null, $isk]);
        $id = (int)$p->lastInsertId();
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'crm_fiyat_listesi', $id, null, ['kod' => $kod, 'grup' => $grup, 'uid' => $uid]);
        }
        return ['ok' => true, 'id' => $id, 'mesaj' => "Fiyat listesi oluşturuldu ($kod)."];
    });
}

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function crm_fiyat_satir_ekle(PDO $pdo, array $g, int $uid): array
{
    $lid   = (int)($g['liste_id'] ?? 0);
    $urun  = mb_substr(trim((string)($g['urun_ad'] ?? '')), 0, 160, 'UTF-8');
    $birim = mb_substr(trim((string)($g['birim'] ?? 'kg')), 0, 20, 'UTF-8') ?: 'kg';
    $fiyat = max(0.0, round(erp_plan_sayi($g['birim_fiyat'] ?? 0), 4));
    $minMk = max(0.0, round(erp_plan_sayi($g['min_miktar'] ?? 0), 3));
    $isk   = max(0.0, min(80.0, round(erp_plan_sayi($g['iskonto_yuzde'] ?? 0), 2)));
    $stokId = isset($g['stok_id']) && (int)$g['stok_id'] > 0 ? (int)$g['stok_id'] : null;

    if ($lid <= 0 || $urun === '' || $fiyat <= 0.0) {
        return ['ok' => false, 'hata' => 'Fiyat listesi, ürün adı ve pozitif birim fiyat zorunludur.', 'kod' => 422];
    }
    $pdo->prepare(
        'INSERT INTO `crm_fiyat_satirlar` (liste_id, stok_id, urun_ad, birim, birim_fiyat, min_miktar, iskonto_yuzde) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$lid, $stokId, $urun, $birim, $fiyat, $minMk, $isk]);
    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'crm_fiyat_satir', (int)$pdo->lastInsertId(), null, ['liste_id' => $lid, 'urun' => $urun, 'fiyat' => $fiyat, 'uid' => $uid]);
    }
    return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'mesaj' => "Fiyat satırı eklendi ($urun · $fiyat)."];
}

/**
 * Verilen liste, ürün ve miktara göre en uygun kademeli (min_miktar) net birim fiyatı çözer.
 * @return array{bulundu:bool,liste_fiyat:float,iskonto_yuzde:float,net_birim_fiyat:float}
 */
function crm_fiyat_hesapla(PDO $pdo, int $listeId, string $urunAd, float $miktar = 1.0): array
{
    $stL = $pdo->prepare('SELECT varsayilan_iskonto FROM `crm_fiyat_listeleri` WHERE id = ? AND aktif = 1');
    $stL->execute([$listeId]);
    $varsIsk = $stL->fetchColumn();
    if ($varsIsk === false) return ['bulundu' => false, 'liste_fiyat' => 0.0, 'iskonto_yuzde' => 0.0, 'net_birim_fiyat' => 0.0];

    $st = $pdo->prepare(
        'SELECT birim_fiyat, iskonto_yuzde FROM `crm_fiyat_satirlar`
         WHERE liste_id = ? AND LOWER(urun_ad) = LOWER(?) AND min_miktar <= ?
         ORDER BY min_miktar DESC, id DESC LIMIT 1'
    );
    $st->execute([$listeId, trim($urunAd), max(0.0, $miktar)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['bulundu' => false, 'liste_fiyat' => 0.0, 'iskonto_yuzde' => (float)$varsIsk, 'net_birim_fiyat' => 0.0];

    $brut = (float)$r['birim_fiyat'];
    $isk  = (float)$r['iskonto_yuzde'] > 0.0 ? (float)$r['iskonto_yuzde'] : (float)$varsIsk;
    $net  = round($brut * (1.0 - ($isk / 100.0)), 4);
    return ['bulundu' => true, 'liste_fiyat' => $brut, 'iskonto_yuzde' => $isk, 'net_birim_fiyat' => $net];
}

// ── 3) Teklif & Tek-Tık Sipariş Dönüşümü ───────────────────────────────────

/** @return array{ok:bool,id?:int,teklif_no?:string,genel_toplam?:float,hata?:string,mesaj?:string,kod?:int} */
function crm_teklif_kaydet(PDO $pdo, array $g, int $uid): array
{
    $musteri = mb_substr(trim((string)($g['musteri_ad'] ?? '')), 0, 160, 'UTF-8');
    $urun    = mb_substr(trim((string)($g['urun_ozet'] ?? '')), 0, 240, 'UTF-8');
    $tarih   = trim((string)($g['tarih'] ?? date('Y-m-d')));
    $gec     = trim((string)($g['gecerlilik_tarih'] ?? date('Y-m-d', strtotime('+15 days'))));
    $miktar  = max(0.001, round(erp_plan_sayi($g['miktar'] ?? 1), 3));
    $fiyat   = round(erp_plan_sayi($g['birim_fiyat'] ?? 0), 4);
    $isk     = max(0.0, min(80.0, round(erp_plan_sayi($g['iskonto_yuzde'] ?? 0), 2)));
    $kdv     = max(0.0, min(30.0, round(erp_plan_sayi($g['kdv_orani'] ?? 20), 2)));
    $pb      = strtoupper(trim((string)($g['para_birimi'] ?? 'TRY')));
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';
    $firsatId = isset($g['firsat_id']) && (int)$g['firsat_id'] > 0 ? (int)$g['firsat_id'] : null;
    $listeId  = isset($g['fiyat_liste_id']) && (int)$g['fiyat_liste_id'] > 0 ? (int)$g['fiyat_liste_id'] : null;

    if ($listeId !== null && $fiyat <= 0.0 && $urun !== '') {
        $coz = crm_fiyat_hesapla($pdo, $listeId, $urun, $miktar);
        if ($coz['bulundu']) {
            $fiyat = $coz['liste_fiyat'];
            if ($isk <= 0.0) $isk = $coz['iskonto_yuzde'];
        }
    }

    if ($musteri === '' || $urun === '' || $fiyat <= 0.0) {
        return ['ok' => false, 'hata' => 'Müşteri, ürün özeti ve birim fiyat zorunludur.', 'kod' => 422];
    }
    if (!erp_plan_tarih_gecerli($tarih)) return ['ok' => false, 'hata' => 'Geçersiz teklif tarihi.', 'kod' => 422];

    $araTop = round($miktar * $fiyat * (1.0 - ($isk / 100.0)), 2);
    $genTop = round($araTop * (1.0 + ($kdv / 100.0)), 2);

    return db_tx($pdo, static function (PDO $p) use ($firsatId, $listeId, $musteri, $tarih, $gec, $urun, $miktar, $fiyat, $isk, $araTop, $kdv, $genTop, $pb, $uid): array {
        $n = (int)$p->query('SELECT COUNT(*) FROM `crm_teklifler`')->fetchColumn() + 1;
        $tNo = sprintf('TKL-%s-%04d', date('Y'), $n);
        while (true) {
            $c = $p->prepare('SELECT 1 FROM `crm_teklifler` WHERE teklif_no = ?');
            $c->execute([$tNo]);
            if (!$c->fetchColumn()) break;
            $n++;
            $tNo = sprintf('TKL-%s-%04d', date('Y'), $n);
        }
        $p->prepare(
            "INSERT INTO `crm_teklifler` (teklif_no, firsat_id, fiyat_liste_id, musteri_ad, tarih, gecerlilik_tarih, urun_ozet, miktar, birim_fiyat, iskonto_yuzde, ara_toplam, kdv_orani, genel_toplam, para_birimi, durum, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'gonderildi', ?)"
        )->execute([$tNo, $firsatId, $listeId, $musteri, $tarih, erp_plan_tarih_gecerli($gec) ? $gec : null, $urun, $miktar, $fiyat, $isk, $araTop, $kdv, $genTop, $pb, $uid ?: null]);
        $id = (int)$p->lastInsertId();
        return ['ok' => true, 'id' => $id, 'teklif_no' => $tNo, 'genel_toplam' => $genTop, 'mesaj' => "Satış teklifi oluşturuldu ($tNo · $genTop $pb)."];
    });
}

/**
 * Onaylanan teklifi idempotent olarak siparişe dönüştürür ve bağlı fırsatı 'kazanildi' yapar.
 * @return array{ok:bool,siparis_ref?:string,hata?:string,mesaj?:string,kod?:int}
 */
function crm_teklif_siparise_donustur(PDO $pdo, int $teklifId, int $uid): array
{
    return db_tx($pdo, static function (PDO $p) use ($teklifId, $uid): array {
        $st = $p->prepare('SELECT * FROM `crm_teklifler` WHERE id = ?' . db_for_update($p));
        $st->execute([$teklifId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return ['ok' => false, 'hata' => 'Teklif bulunamadı.', 'kod' => 404];
        if ((string)$t['durum'] === 'siparise_donustu') {
            return ['ok' => false, 'hata' => 'Bu teklif zaten siparişe dönüştürülmüş (' . $t['siparis_ref'] . ').', 'kod' => 409];
        }

        $sipRef = 'SIP-' . date('Y') . '-' . str_pad((string)$teklifId, 4, '0', STR_PAD_LEFT);
        $p->prepare("UPDATE `crm_teklifler` SET durum='siparise_donustu', siparis_ref=? WHERE id=?")->execute([$sipRef, $teklifId]);
        if (!empty($t['firsat_id'])) {
            $p->prepare("UPDATE `crm_firsatlar` SET asama='kazanildi', olasilik_yuzde=100 WHERE id=?")->execute([(int)$t['firsat_id']]);
        }
        if (function_exists('audit_log_event')) {
            audit_log_event('convert', 'crm_teklif', $teklifId, ['durum' => $t['durum']], ['durum' => 'siparise_donustu', 'siparis_ref' => $sipRef, 'uid' => $uid]);
        }
        return ['ok' => true, 'siparis_ref' => $sipRef, 'mesaj' => $t['teklif_no'] . " siparişe dönüştürüldü ($sipRef)."];
    });
}

// ── 4) Müşteri Şikayet / Kalite Claim ──────────────────────────────────────

/** @return array{ok:bool,id?:int,claim_no?:string,hata?:string,mesaj?:string,kod?:int} */
function crm_claim_kaydet(PDO $pdo, array $g, int $uid): array
{
    $musteri = mb_substr(trim((string)($g['musteri_ad'] ?? '')), 0, 160, 'UTF-8');
    $sevk    = mb_substr(trim((string)($g['sevkiyat_ref'] ?? '')), 0, 80, 'UTF-8');
    $tarih   = trim((string)($g['tarih'] ?? date('Y-m-d')));
    $kat     = (string)($g['kategori'] ?? 'kalite');
    if (!in_array($kat, ['kalite', 'fire', 'teslimat', 'ambalaj', 'fatura'], true)) $kat = 'kalite';
    $talep   = max(0.0, round(erp_plan_sayi($g['talep_tutar'] ?? 0), 2));
    $pb      = strtoupper(trim((string)($g['para_birimi'] ?? 'EUR')));
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'EUR';
    $ack     = mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 500, 'UTF-8');

    if ($musteri === '' || !erp_plan_tarih_gecerli($tarih)) {
        return ['ok' => false, 'hata' => 'Müşteri adı ve geçerli tarih zorunludur.', 'kod' => 422];
    }
    return db_tx($pdo, static function (PDO $p) use ($musteri, $sevk, $tarih, $kat, $talep, $pb, $ack, $uid): array {
        $n = (int)$p->query('SELECT COUNT(*) FROM `crm_claimler`')->fetchColumn() + 1;
        $cNo = sprintf('CLM-%s-%04d', date('Y'), $n);
        $p->prepare(
            "INSERT INTO `crm_claimler` (claim_no, musteri_ad, sevkiyat_ref, tarih, kategori, talep_tutar, kabul_tutar, para_birimi, durum, aciklama, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'acik', ?, ?)"
        )->execute([$cNo, $musteri, $sevk, $tarih, $kat, $talep, $pb, $ack, $uid ?: null]);
        return ['ok' => true, 'id' => (int)$p->lastInsertId(), 'claim_no' => $cNo, 'mesaj' => "Kalite claim kaydı açıldı ($cNo)."];
    });
}

/** @return array{ok:bool,hata?:string,mesaj?:string,kod?:int} */
function crm_claim_sonuclandir(PDO $pdo, int $claimId, string $durum, float $kabulTutar, string $not, int $uid): array
{
    if (!in_array($durum, ['cozuldu', 'reddedildi'], true)) return ['ok' => false, 'hata' => 'Geçersiz claim durumu.', 'kod' => 422];
    $st = $pdo->prepare('SELECT claim_no FROM `crm_claimler` WHERE id = ?');
    $st->execute([$claimId]);
    $no = $st->fetchColumn();
    if (!$no) return ['ok' => false, 'hata' => 'Claim kaydı bulunamadı.', 'kod' => 404];
    $kabul = $durum === 'cozuldu' ? max(0.0, round($kabulTutar, 2)) : 0.0;
    $pdo->prepare('UPDATE `crm_claimler` SET durum=?, kabul_tutar=?, cozum_notu=? WHERE id=?')
        ->execute([$durum, $kabul, mb_substr(trim($not), 0, 500, 'UTF-8'), $claimId]);
    if (function_exists('audit_log_event')) {
        audit_log_event('resolve', 'crm_claim', $claimId, null, ['durum' => $durum, 'kabul_tutar' => $kabul, 'uid' => $uid]);
    }
    return ['ok' => true, 'mesaj' => "$no sonuçlandırıldı (" . strtoupper($durum) . ")."];
}

// ── Listeler ───────────────────────────────────────────────────────────────

/** @return list<array<string,mixed>> */
function crm_firsat_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM `crm_firsatlar` ORDER BY id DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function crm_fiyat_listesi_tumu(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM `crm_fiyat_listeleri` ORDER BY aktif DESC, id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $st = $pdo->prepare('SELECT * FROM `crm_fiyat_satirlar` WHERE liste_id = ? ORDER BY urun_ad ASC, min_miktar ASC');
        $st->execute([(int)$r['id']]);
        $r['satirlar'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($r);
    return $rows;
}

/** @return list<array<string,mixed>> */
function crm_teklif_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM `crm_teklifler` ORDER BY id DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function crm_claim_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM `crm_claimler` ORDER BY id DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC);
}
