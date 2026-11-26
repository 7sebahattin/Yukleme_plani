<?php
// =========================================================
// config/satinalma_core.php — ERP-3 Satın Alma Yönetimi & 3-Way Match
//
// Akış:
//   Satın Alma Talebi (sa_talepler + sa_talep_satirlar)
//   → Tedarikçi Teklif Karşılaştırma (sa_teklifler)
//   → Verilen Satın Alma Siparişi (sa_siparisler + sa_siparis_satirlar)
//   → Mal Kabul / Giriş İrsaliyesi (sa_mal_kabul + sa_mal_kabul_satirlar → WMS Lot girişi)
//   → 3-Way Match (sa_eslestirmeler: Sipariş ↔ İrsaliye ↔ Fatura miktar & fiyat tolerans kontrolü)
//   → Tedarikçi Performans Puanlaması
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/wms_stok_core.php';

/** @return list<string> */
function satinalma_tablolar(): array
{
    return [
        'sa_talepler', 'sa_talep_satirlar', 'sa_teklifler',
        'sa_siparisler', 'sa_siparis_satirlar',
        'sa_mal_kabul', 'sa_mal_kabul_satirlar', 'sa_eslestirmeler',
    ];
}

/** @return list<string> */
function satinalma_eksik_tablolar(PDO $pdo): array
{
    $e = [];
    foreach (satinalma_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $e[] = $t;
    }
    return $e;
}

function satinalma_hazir(PDO $pdo): bool
{
    return satinalma_eksik_tablolar($pdo) === [];
}

function satinalma_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = satinalma_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Satın Alma tabloları kurulmamış (' . implode(', ', $eksik) . '). migrate.php#satinalma kartını çalıştırın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header') && function_exists('erp_op_sema_uyari')) {
        render_header('Satın Alma & 3-Way Match');
        echo erp_op_sema_uyari($eksik, 'satinalma', 'Satın Alma & 3-Way Match (ERP-3)');
        if (function_exists('render_footer')) render_footer();
        exit;
    }
    http_response_code(503);
    exit('Satın Alma şeması kurulmamış.');
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function satinalma_migrate(PDO $pdo): array
{
    $sqlite = db_is_sqlite($pdo);
    $ai  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'sa_talepler' => "CREATE TABLE IF NOT EXISTS `sa_talepler` (
            `id` $ai,
            `talep_no` VARCHAR(40) NOT NULL UNIQUE,
            `tarih` DATE NOT NULL,
            `termin_tarih` DATE NULL,
            `depo` VARCHAR(120) NOT NULL DEFAULT 'MERKEZ',
            `departman` VARCHAR(100) NOT NULL DEFAULT '',
            `aciklama` VARCHAR(500) NOT NULL DEFAULT '',
            `durum` VARCHAR(30) NOT NULL DEFAULT 'acik',
            `kaynak` VARCHAR(40) NOT NULL DEFAULT 'manuel',
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'sa_talep_satirlar' => "CREATE TABLE IF NOT EXISTS `sa_talep_satirlar` (
            `id` $ai,
            `talep_id` INT NOT NULL,
            `stok_id` INT NULL,
            `urun_ad` VARCHAR(180) NOT NULL,
            `birim` VARCHAR(20) NOT NULL DEFAULT 'kg',
            `miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `hedef_fiyat` DECIMAL(14,4) NOT NULL DEFAULT 0.0000
        )$eng",
        'sa_teklifler' => "CREATE TABLE IF NOT EXISTS `sa_teklifler` (
            `id` $ai,
            `talep_id` INT NOT NULL,
            `tedarikci_cari_id` INT NULL,
            `tedarikci_ad` VARCHAR(200) NOT NULL,
            `birim_fiyat` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
            `toplam_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `teslim_gun` INT NOT NULL DEFAULT 3,
            `vade_gun` INT NOT NULL DEFAULT 30,
            `secildi` TINYINT NOT NULL DEFAULT 0,
            `notlar` VARCHAR(400) NOT NULL DEFAULT '',
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'sa_siparisler' => "CREATE TABLE IF NOT EXISTS `sa_siparisler` (
            `id` $ai,
            `siparis_no` VARCHAR(40) NOT NULL UNIQUE,
            `talep_id` INT NULL,
            `teklif_id` INT NULL,
            `tarih` DATE NOT NULL,
            `termin_tarih` DATE NULL,
            `depo` VARCHAR(120) NOT NULL DEFAULT 'MERKEZ',
            `tedarikci_cari_id` INT NULL,
            `tedarikci_ad` VARCHAR(200) NOT NULL,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `toplam_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `durum` VARCHAR(30) NOT NULL DEFAULT 'acik',
            `aciklama` VARCHAR(500) NOT NULL DEFAULT '',
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'sa_siparis_satirlar' => "CREATE TABLE IF NOT EXISTS `sa_siparis_satirlar` (
            `id` $ai,
            `siparis_id` INT NOT NULL,
            `stok_id` INT NULL,
            `urun_ad` VARCHAR(180) NOT NULL,
            `birim` VARCHAR(20) NOT NULL DEFAULT 'kg',
            `siparis_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `teslim_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `birim_fiyat` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
            `tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00
        )$eng",
        'sa_mal_kabul' => "CREATE TABLE IF NOT EXISTS `sa_mal_kabul` (
            `id` $ai,
            `kabul_no` VARCHAR(40) NOT NULL UNIQUE,
            `siparis_id` INT NOT NULL,
            `tarih` DATE NOT NULL,
            `irsaliye_no` VARCHAR(60) NOT NULL DEFAULT '',
            `depo` VARCHAR(120) NOT NULL DEFAULT 'MERKEZ',
            `kalite_puani` TINYINT NOT NULL DEFAULT 5,
            `zamaninda` TINYINT NOT NULL DEFAULT 1,
            `aciklama` VARCHAR(400) NOT NULL DEFAULT '',
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'sa_mal_kabul_satirlar' => "CREATE TABLE IF NOT EXISTS `sa_mal_kabul_satirlar` (
            `id` $ai,
            `kabul_id` INT NOT NULL,
            `siparis_satir_id` INT NOT NULL,
            `stok_id` INT NULL,
            `urun_ad` VARCHAR(180) NOT NULL,
            `kabul_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `red_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `lot_no` VARCHAR(60) NOT NULL DEFAULT ''
        )$eng",
        'sa_eslestirmeler' => "CREATE TABLE IF NOT EXISTS `sa_eslestirmeler` (
            `id` $ai,
            `siparis_id` INT NOT NULL,
            `kabul_id` INT NOT NULL,
            `fatura_id` INT NULL,
            `fatura_no` VARCHAR(60) NOT NULL DEFAULT '',
            `po_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `grn_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `fatura_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `po_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `fatura_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `miktar_sapma_yuzde` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            `tutar_sapma_yuzde` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            `tolerans_yuzde` DECIMAL(6,2) NOT NULL DEFAULT 2.00,
            `sonuc` VARCHAR(20) NOT NULL DEFAULT 'uyumlu',
            `notlar` VARCHAR(400) NOT NULL DEFAULT '',
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
    ];

    $rapor = [];
    foreach ($ddl as $t => $sql) {
        $var = db_table_exists($pdo, $t);
        $pdo->exec($sql);
        $rapor[] = [
            'tablo' => $t,
            'durum' => $var ? 'var' : 'olusturuldu',
            'mesaj' => $var ? 'Tablo zaten mevcut.' : 'Tablo oluşturuldu.',
        ];
    }
    return $rapor;
}

function sa_sirali_no(PDO $p, string $tablo, string $kolon, string $onek, string $tarih): string
{
    $yil = substr($tarih, 0, 4);
    $st = $p->prepare("SELECT COUNT(*) FROM `$tablo` WHERE `$kolon` LIKE ?");
    $st->execute(["$onek-$yil-%"]);
    $n = (int)$st->fetchColumn() + 1;
    $kod = sprintf('%s-%s-%05d', $onek, $yil, $n);
    while (true) {
        $c = $p->prepare("SELECT 1 FROM `$tablo` WHERE `$kolon` = ?");
        $c->execute([$kod]);
        if (!$c->fetchColumn()) return $kod;
        $n++;
        $kod = sprintf('%s-%s-%05d', $onek, $yil, $n);
    }
}

// ── 1) Satın Alma Talebi ───────────────────────────────────────────────────

/** @return array{ok:bool,id?:int,talep_no?:string,hata?:string,mesaj?:string,kod?:int} */
function sa_talep_kaydet(PDO $pdo, array $g, int $uid): array
{
    $tarih  = trim((string)($g['tarih'] ?? date('Y-m-d')));
    $termin = trim((string)($g['termin_tarih'] ?? ''));
    $depo   = mb_strtoupper(mb_substr(trim((string)($g['depo'] ?? 'MERKEZ')), 0, 120, 'UTF-8'), 'UTF-8');
    if ($depo === '') $depo = 'MERKEZ';
    $dept   = mb_substr(trim((string)($g['departman'] ?? '')), 0, 100, 'UTF-8');
    $ack    = mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 500, 'UTF-8');
    $kaynak = mb_substr(trim((string)($g['kaynak'] ?? 'manuel')), 0, 40, 'UTF-8');
    $satirlar = is_array($g['satirlar'] ?? null) ? $g['satirlar'] : [];

    if (!erp_op_tarih_gecerli($tarih)) return ['ok' => false, 'hata' => 'Geçersiz talep tarihi.', 'kod' => 422];
    if ($termin !== '' && !erp_op_tarih_gecerli($termin)) return ['ok' => false, 'hata' => 'Geçersiz termin tarihi.', 'kod' => 422];
    if ($satirlar === []) return ['ok' => false, 'hata' => 'En az bir satın alma kalemi eklemelisiniz.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($tarih, $termin, $depo, $dept, $ack, $kaynak, $satirlar, $uid): array {
        $talepNo = sa_sirali_no($p, 'sa_talepler', 'talep_no', 'SAT', $tarih);
        $p->prepare(
            "INSERT INTO `sa_talepler` (talep_no, tarih, termin_tarih, depo, departman, aciklama, durum, kaynak, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, 'acik', ?, ?)"
        )->execute([$talepNo, $tarih, $termin !== '' ? $termin : null, $depo, $dept, $ack, $kaynak, $uid ?: null]);
        $tid = (int)$p->lastInsertId();

        $eklenen = 0;
        foreach ($satirlar as $s) {
            $ad = mb_substr(trim((string)($s['urun_ad'] ?? '')), 0, 180, 'UTF-8');
            $miktar = round(erp_op_sayi($s['miktar'] ?? 0), 3);
            if ($ad === '' || $miktar <= 0.0) continue;
            $sid = isset($s['stok_id']) && (int)$s['stok_id'] > 0 ? (int)$s['stok_id'] : null;
            $birim = mb_substr(trim((string)($s['birim'] ?? 'kg')), 0, 20, 'UTF-8');
            $hf = max(0.0, round(erp_op_sayi($s['hedef_fiyat'] ?? 0), 4));
            $p->prepare('INSERT INTO `sa_talep_satirlar` (talep_id, stok_id, urun_ad, birim, miktar, hedef_fiyat) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$tid, $sid, $ad, $birim, $miktar, $hf]);
            $eklenen++;
        }
        if ($eklenen === 0) throw new RuntimeException('Geçerli satın alma satırı yok.');
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'sa_talep', $tid, null, ['talep_no' => $talepNo, 'kalem' => $eklenen]);
        }
        return ['ok' => true, 'id' => $tid, 'talep_no' => $talepNo, 'mesaj' => "Satın alma talebi oluşturuldu ($talepNo)."];
    });
}

// ── 2) Tedarikçi Teklif Toplama & Karşılaştırma ────────────────────────────

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function sa_teklif_ekle(PDO $pdo, array $g, int $uid): array
{
    $talepId = (int)($g['talep_id'] ?? 0);
    $cariId  = isset($g['tedarikci_cari_id']) && (int)$g['tedarikci_cari_id'] > 0 ? (int)$g['tedarikci_cari_id'] : null;
    $tedAd   = mb_substr(trim((string)($g['tedarikci_ad'] ?? '')), 0, 200, 'UTF-8');
    $bf      = max(0.0, round(erp_op_sayi($g['birim_fiyat'] ?? 0), 4));
    $toplam  = max(0.0, round(erp_op_sayi($g['toplam_tutar'] ?? 0), 2));
    $pb      = strtoupper(trim((string)($g['para_birimi'] ?? 'TRY')));
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';
    $teslim  = max(0, (int)($g['teslim_gun'] ?? 3));
    $vade    = max(0, (int)($g['vade_gun'] ?? 30));
    $notlar  = mb_substr(trim((string)($g['notlar'] ?? '')), 0, 400, 'UTF-8');

    if ($talepId <= 0) return ['ok' => false, 'hata' => 'Geçerli bir satın alma talebi seçin.', 'kod' => 422];
    if ($tedAd === '') return ['ok' => false, 'hata' => 'Tedarikçi adı zorunludur.', 'kod' => 422];
    if ($toplam <= 0.0 && $bf <= 0.0) return ['ok' => false, 'hata' => 'Birim fiyat veya toplam tutar sıfırdan büyük olmalıdır.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($talepId, $cariId, $tedAd, $bf, $toplam, $pb, $teslim, $vade, $notlar, $uid): array {
        $stT = $p->prepare('SELECT id FROM `sa_talepler` WHERE id = ?');
        $stT->execute([$talepId]);
        if (!$stT->fetchColumn()) return ['ok' => false, 'hata' => 'Satın alma talebi bulunamadı.', 'kod' => 404];
        if ($toplam <= 0.0) {
            $stM = $p->prepare('SELECT COALESCE(SUM(miktar), 0) FROM `sa_talep_satirlar` WHERE talep_id = ?');
            $stM->execute([$talepId]);
            $toplam = round((float)$stM->fetchColumn() * $bf, 2);
        }
        $p->prepare(
            'INSERT INTO `sa_teklifler` (talep_id, tedarikci_cari_id, tedarikci_ad, birim_fiyat, toplam_tutar, para_birimi, teslim_gun, vade_gun, notlar) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$talepId, $cariId, $tedAd, $bf, $toplam, $pb, $teslim, $vade, $notlar]);
        $id = (int)$p->lastInsertId();
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'sa_teklif', $id, null, ['talep_id' => $talepId, 'tedarikci' => $tedAd, 'toplam' => $toplam, 'uid' => $uid]);
        }
        return ['ok' => true, 'id' => $id, 'mesaj' => "Tedarikçi teklifi eklendi ($tedAd)."];
    });
}

// ── 3) Verilen Satın Alma Siparişi (Tekliften veya Doğrudan) ───────────────

/** @return array{ok:bool,id?:int,siparis_no?:string,hata?:string,mesaj?:string,kod?:int} */
function sa_siparis_olustur(PDO $pdo, array $g, int $uid): array
{
    $teklifId = isset($g['teklif_id']) && (int)$g['teklif_id'] > 0 ? (int)$g['teklif_id'] : null;
    $tarih    = trim((string)($g['tarih'] ?? date('Y-m-d')));
    $termin   = trim((string)($g['termin_tarih'] ?? ''));
    $depo     = mb_strtoupper(mb_substr(trim((string)($g['depo'] ?? 'MERKEZ')), 0, 120, 'UTF-8'), 'UTF-8');
    if ($depo === '') $depo = 'MERKEZ';

    if (!erp_op_tarih_gecerli($tarih)) return ['ok' => false, 'hata' => 'Geçersiz sipariş tarihi.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($g, $teklifId, $tarih, $termin, $depo, $uid): array {
        $talepId = isset($g['talep_id']) && (int)$g['talep_id'] > 0 ? (int)$g['talep_id'] : null;
        $cariId  = isset($g['tedarikci_cari_id']) && (int)$g['tedarikci_cari_id'] > 0 ? (int)$g['tedarikci_cari_id'] : null;
        $tedAd   = mb_substr(trim((string)($g['tedarikci_ad'] ?? '')), 0, 200, 'UTF-8');
        $pb      = strtoupper(trim((string)($g['para_birimi'] ?? 'TRY')));
        $ack     = mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 500, 'UTF-8');
        $satirlar = is_array($g['satirlar'] ?? null) ? $g['satirlar'] : [];

        if ($teklifId !== null) {
            $stTk = $p->prepare('SELECT * FROM `sa_teklifler` WHERE id = ?' . db_for_update($p));
            $stTk->execute([$teklifId]);
            $tk = $stTk->fetch(PDO::FETCH_ASSOC);
            if (!$tk) return ['ok' => false, 'hata' => 'Teklif bulunamadı.', 'kod' => 404];
            $talepId = (int)$tk['talep_id'];
            $cariId  = $tk['tedarikci_cari_id'] ? (int)$tk['tedarikci_cari_id'] : null;
            $tedAd   = (string)$tk['tedarikci_ad'];
            $pb      = (string)$tk['para_birimi'];
            $p->prepare('UPDATE `sa_teklifler` SET secildi = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE talep_id = ?')->execute([$teklifId, $talepId]);
            $p->prepare("UPDATE `sa_talepler` SET durum = 'siparise_donustu' WHERE id = ?")->execute([$talepId]);

            $stS = $p->prepare('SELECT * FROM `sa_talep_satirlar` WHERE talep_id = ?');
            $stS->execute([$talepId]);
            $satirlar = [];
            foreach ($stS->fetchAll(PDO::FETCH_ASSOC) as $ts) {
                $bf = (float)$tk['birim_fiyat'] > 0 ? (float)$tk['birim_fiyat'] : (float)$ts['hedef_fiyat'];
                $satirlar[] = [
                    'stok_id'     => $ts['stok_id'],
                    'urun_ad'     => $ts['urun_ad'],
                    'birim'       => $ts['birim'],
                    'miktar'      => (float)$ts['miktar'],
                    'birim_fiyat' => $bf,
                ];
            }
        }

        if ($tedAd === '') return ['ok' => false, 'hata' => 'Tedarikçi adı zorunludur.', 'kod' => 422];
        if ($satirlar === []) return ['ok' => false, 'hata' => 'Sipariş satırı boş olamaz.', 'kod' => 422];
        if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';

        $sipNo = sa_sirali_no($p, 'sa_siparisler', 'siparis_no', 'SAS', $tarih);
        $p->prepare(
            "INSERT INTO `sa_siparisler` (siparis_no, talep_id, teklif_id, tarih, termin_tarih, depo, tedarikci_cari_id, tedarikci_ad, para_birimi, toplam_tutar, durum, aciklama, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'acik', ?, ?)"
        )->execute([$sipNo, $talepId, $teklifId, $tarih, $termin !== '' ? $termin : null, $depo, $cariId, $tedAd, $pb, $ack, $uid ?: null]);
        $sid = (int)$p->lastInsertId();

        $toplam = 0.0;
        foreach ($satirlar as $s) {
            $ad = mb_substr(trim((string)($s['urun_ad'] ?? '')), 0, 180, 'UTF-8');
            $m  = round(erp_op_sayi($s['miktar'] ?? 0), 3);
            $bf = max(0.0, round(erp_op_sayi($s['birim_fiyat'] ?? 0), 4));
            if ($ad === '' || $m <= 0.0) continue;
            $tutar = round($m * $bf, 2);
            $toplam = round($toplam + $tutar, 2);
            $p->prepare(
                'INSERT INTO `sa_siparis_satirlar` (siparis_id, stok_id, urun_ad, birim, siparis_miktar, teslim_miktar, birim_fiyat, tutar) VALUES (?, ?, ?, ?, ?, 0, ?, ?)'
            )->execute([$sid, !empty($s['stok_id']) ? (int)$s['stok_id'] : null, $ad, mb_substr(trim((string)($s['birim'] ?? 'kg')), 0, 20, 'UTF-8'), $m, $bf, $tutar]);
        }
        $p->prepare('UPDATE `sa_siparisler` SET toplam_tutar = ? WHERE id = ?')->execute([$toplam, $sid]);
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'sa_siparis', $sid, null, ['siparis_no' => $sipNo, 'tedarikci' => $tedAd, 'toplam' => $toplam]);
        }
        return ['ok' => true, 'id' => $sid, 'siparis_no' => $sipNo, 'mesaj' => "Satın alma siparişi oluşturuldu ($sipNo)."];
    });
}

// ── 4) Mal Kabul / Giriş İrsaliyesi (+ WMS Lot Girişi) ─────────────────────

/** @return array{ok:bool,id?:int,kabul_no?:string,hata?:string,mesaj?:string,kod?:int} */
function sa_mal_kabul_kaydet(PDO $pdo, array $g, int $uid): array
{
    $sipId   = (int)($g['siparis_id'] ?? 0);
    $tarih   = trim((string)($g['tarih'] ?? date('Y-m-d')));
    $irsNo   = mb_substr(trim((string)($g['irsaliye_no'] ?? '')), 0, 60, 'UTF-8');
    $kalite  = max(1, min(5, (int)($g['kalite_puani'] ?? 5)));
    $zaman   = isset($g['zamaninda']) ? (!empty($g['zamaninda']) ? 1 : 0) : 1;
    $ack     = mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 400, 'UTF-8');
    $satirlar = is_array($g['satirlar'] ?? null) ? $g['satirlar'] : [];

    if ($sipId <= 0) return ['ok' => false, 'hata' => 'Geçerli bir satın alma siparişi seçin.', 'kod' => 422];
    if (!erp_op_tarih_gecerli($tarih)) return ['ok' => false, 'hata' => 'Geçersiz mal kabul tarihi.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($sipId, $tarih, $irsNo, $kalite, $zaman, $ack, $satirlar, $uid): array {
        $stS = $p->prepare('SELECT * FROM `sa_siparisler` WHERE id = ?' . db_for_update($p));
        $stS->execute([$sipId]);
        $sip = $stS->fetch(PDO::FETCH_ASSOC);
        if (!$sip) return ['ok' => false, 'hata' => 'Sipariş bulunamadı.', 'kod' => 404];

        $stLines = $p->prepare('SELECT * FROM `sa_siparis_satirlar` WHERE siparis_id = ?' . db_for_update($p));
        $stLines->execute([$sipId]);
        $sipSatirlar = [];
        foreach ($stLines->fetchAll(PDO::FETCH_ASSOC) as $sl) $sipSatirlar[(int)$sl['id']] = $sl;
        if ($sipSatirlar === []) return ['ok' => false, 'hata' => 'Siparişte satır bulunamadı.', 'kod' => 422];

        // Satırlar açıkça verilmediyse tüm kalan miktarları kabul et
        if ($satirlar === []) {
            foreach ($sipSatirlar as $sl) {
                $kalan = round((float)$sl['siparis_miktar'] - (float)$sl['teslim_miktar'], 3);
                if ($kalan > 0) {
                    $satirlar[] = ['siparis_satir_id' => (int)$sl['id'], 'kabul_miktar' => $kalan, 'red_miktar' => 0];
                }
            }
        }
        if ($satirlar === []) return ['ok' => false, 'hata' => 'Kabul edilecek miktar yok (sipariş zaten teslim alınmış).', 'kod' => 409];

        $kabulNo = sa_sirali_no($p, 'sa_mal_kabul', 'kabul_no', 'MK', $tarih);
        $p->prepare(
            'INSERT INTO `sa_mal_kabul` (kabul_no, siparis_id, tarih, irsaliye_no, depo, kalite_puani, zamaninda, aciklama, olusturan_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$kabulNo, $sipId, $tarih, $irsNo, (string)$sip['depo'], $kalite, $zaman, $ack, $uid ?: null]);
        $kid = (int)$p->lastInsertId();

        $wmsHazir = wms_stok_hazir($p);
        $eklenen = 0;
        foreach ($satirlar as $s) {
            $ssid = (int)($s['siparis_satir_id'] ?? 0);
            if (!isset($sipSatirlar[$ssid])) continue;
            $sl = $sipSatirlar[$ssid];
            $km = max(0.0, round(erp_op_sayi($s['kabul_miktar'] ?? 0), 3));
            $rm = max(0.0, round(erp_op_sayi($s['red_miktar'] ?? 0), 3));
            if ($km <= 0.0 && $rm <= 0.0) continue;
            $lotNo = mb_strtoupper(mb_substr(trim((string)($s['lot_no'] ?? '')), 0, 60, 'UTF-8'), 'UTF-8');
            if ($lotNo === '' && $km > 0) $lotNo = $kabulNo . '-' . $ssid;

            $p->prepare('INSERT INTO `sa_mal_kabul_satirlar` (kabul_id, siparis_satir_id, stok_id, urun_ad, kabul_miktar, red_miktar, lot_no) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$kid, $ssid, $sl['stok_id'], (string)$sl['urun_ad'], $km, $rm, $lotNo]);
            $p->prepare('UPDATE `sa_siparis_satirlar` SET teslim_miktar = teslim_miktar + ? WHERE id = ?')->execute([$km, $ssid]);

            // Stok ID varsa otomatik WMS Lot girişi oluştur
            if ($wmsHazir && !empty($sl['stok_id']) && $km > 0) {
                wms_lot_giris($p, [
                    'stok_id'       => (int)$sl['stok_id'],
                    'depo'          => (string)$sip['depo'],
                    'lot_no'        => $lotNo,
                    'uretim_tarih'  => $tarih,
                    'skt_tarih'     => (string)($s['skt_tarih'] ?? ''),
                    'miktar'        => $km,
                    'birim_maliyet' => (float)$sl['birim_fiyat'],
                    'para_birimi'   => (string)$sip['para_birimi'],
                    'kaynak_modul'  => 'sa_mal_kabul',
                    'kaynak_id'     => $kid,
                ], $uid);
            }
            $eklenen++;
        }
        if ($eklenen === 0) throw new RuntimeException('Geçerli mal kabul satırı yok.');

        // Sipariş tamamlanma durumunu güncelle
        $stRem = $p->prepare('SELECT SUM(CASE WHEN teslim_miktar + 0.001 < siparis_miktar THEN 1 ELSE 0 END) FROM `sa_siparis_satirlar` WHERE siparis_id = ?');
        $stRem->execute([$sipId]);
        $eksikSatir = (int)$stRem->fetchColumn();
        $yeniDurum = $eksikSatir === 0 ? 'teslim_alindi' : 'kismi_teslim';
        $p->prepare('UPDATE `sa_siparisler` SET durum = ? WHERE id = ?')->execute([$yeniDurum, $sipId]);

        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'sa_mal_kabul', $kid, null, ['kabul_no' => $kabulNo, 'siparis_id' => $sipId, 'durum' => $yeniDurum]);
        }
        return ['ok' => true, 'id' => $kid, 'kabul_no' => $kabulNo, 'mesaj' => "Mal kabul kaydedildi ($kabulNo)."];
    });
}

// ── 5) 3-Way Match (Sipariş ↔ Mal Kabul İrsaliyesi ↔ Alış Faturası) ────────

/**
 * 3-Way Match: Sipariş (PO) ↔ Mal Kabul İrsaliyesi (GRN) ↔ Alış Faturası miktar & tutar sapma kontrolü.
 * Tolerans (varsayılan %2): <= tolerans → 'uyumlu' · tolerans < sapma <= %10 → 'sapma_var' · > %10 → 'blokaj'.
 * @return array{ok:bool,id?:int,sonuc?:string,miktar_sapma_yuzde?:float,tutar_sapma_yuzde?:float,hata?:string,mesaj?:string,kod?:int}
 */
function sa_uc_yonlu_eslestir(PDO $pdo, array $g, int $uid): array
{
    $sipId   = (int)($g['siparis_id'] ?? 0);
    $kabulId = (int)($g['kabul_id'] ?? 0);
    $fatId   = isset($g['fatura_id']) && (int)$g['fatura_id'] > 0 ? (int)$g['fatura_id'] : null;
    $fatNo   = mb_substr(trim((string)($g['fatura_no'] ?? '')), 0, 60, 'UTF-8');
    $fatMik  = max(0.0, round(erp_op_sayi($g['fatura_miktar'] ?? 0), 3));
    $fatTut  = max(0.0, round(erp_op_sayi($g['fatura_tutar'] ?? 0), 2));
    $tol     = max(0.0, min(50.0, round(erp_op_sayi($g['tolerans_yuzde'] ?? 2.0), 2)));
    $notlar  = mb_substr(trim((string)($g['notlar'] ?? '')), 0, 400, 'UTF-8');

    if ($sipId <= 0 || $kabulId <= 0) return ['ok' => false, 'hata' => 'Sipariş ve Mal Kabul kaydı seçilmelidir.', 'kod' => 422];
    if ($fatTut <= 0.0) return ['ok' => false, 'hata' => 'Fatura tutarı sıfırdan büyük olmalıdır.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($sipId, $kabulId, $fatId, $fatNo, $fatMik, $fatTut, $tol, $notlar, $uid): array {
        $stS = $p->prepare('SELECT * FROM `sa_siparisler` WHERE id = ?');
        $stS->execute([$sipId]);
        $sip = $stS->fetch(PDO::FETCH_ASSOC);
        if (!$sip) return ['ok' => false, 'hata' => 'Sipariş bulunamadı.', 'kod' => 404];

        $stK = $p->prepare('SELECT * FROM `sa_mal_kabul` WHERE id = ? AND siparis_id = ?');
        $stK->execute([$kabulId, $sipId]);
        if (!$stK->fetch(PDO::FETCH_ASSOC)) return ['ok' => false, 'hata' => 'Seçilen mal kabul bu siparişe ait değil.', 'kod' => 422];

        $poMik  = round((float)$p->query("SELECT COALESCE(SUM(siparis_miktar), 0) FROM `sa_siparis_satirlar` WHERE siparis_id = " . $sipId)->fetchColumn(), 3);
        $grnMik = round((float)$p->query("SELECT COALESCE(SUM(kabul_miktar), 0) FROM `sa_mal_kabul_satirlar` WHERE kabul_id = " . $kabulId)->fetchColumn(), 3);
        $poTut  = round((float)$sip['toplam_tutar'], 2);
        if ($fatMik <= 0.0) $fatMik = $grnMik;

        // Sapma hesapları: Fatura miktarı GRN (teslim alınan) miktarıyla, Fatura tutarı PO tutarıyla karşılaştırılır
        $mikSapma = $grnMik > 0 ? round((abs($fatMik - $grnMik) / $grnMik) * 100.0, 2) : ($fatMik > 0 ? 100.0 : 0.0);
        $tutSapma = $poTut > 0 ? round((abs($fatTut - $poTut) / $poTut) * 100.0, 2) : ($fatTut > 0 ? 100.0 : 0.0);
        $maxSapma = max($mikSapma, $tutSapma);

        if ($maxSapma <= $tol) $sonuc = 'uyumlu';
        elseif ($maxSapma <= 10.0) $sonuc = 'sapma_var';
        else $sonuc = 'blokaj';

        $p->prepare(
            'INSERT INTO `sa_eslestirmeler` (siparis_id, kabul_id, fatura_id, fatura_no, po_miktar, grn_miktar, fatura_miktar, po_tutar, fatura_tutar, miktar_sapma_yuzde, tutar_sapma_yuzde, tolerans_yuzde, sonuc, notlar, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$sipId, $kabulId, $fatId, $fatNo, $poMik, $grnMik, $fatMik, $poTut, $fatTut, $mikSapma, $tutSapma, $tol, $sonuc, $notlar, $uid ?: null]);
        $eid = (int)$p->lastInsertId();

        if ($sonuc === 'uyumlu') {
            $p->prepare("UPDATE `sa_siparisler` SET durum = 'faturalandi' WHERE id = ?")->execute([$sipId]);
        }
        if (function_exists('audit_log_event')) {
            audit_log_event('match', 'sa_3way_match', $eid, null, ['siparis_id' => $sipId, 'kabul_id' => $kabulId, 'sonuc' => $sonuc, 'max_sapma' => $maxSapma]);
        }
        return [
            'ok'                 => true,
            'id'                 => $eid,
            'sonuc'              => $sonuc,
            'miktar_sapma_yuzde' => $mikSapma,
            'tutar_sapma_yuzde'  => $tutSapma,
            'mesaj'              => sprintf('3-Way Match tamamlandı: %s (Miktar sapma: %%%.2f, Tutar sapma: %%%.2f).', strtoupper($sonuc), $mikSapma, $tutSapma),
        ];
    });
}

// ── Okuyucu Listeler & Tedarikçi Performansı ───────────────────────────────

/** @return list<array<string,mixed>> */
function sa_talep_listesi(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM `sa_talepler` ORDER BY tarih DESC, id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $st = $pdo->prepare('SELECT * FROM `sa_talep_satirlar` WHERE talep_id = ?');
        $st->execute([(int)$r['id']]);
        $r['satirlar'] = $st->fetchAll(PDO::FETCH_ASSOC);
        $stTk = $pdo->prepare('SELECT * FROM `sa_teklifler` WHERE talep_id = ? ORDER BY toplam_tutar ASC');
        $stTk->execute([(int)$r['id']]);
        $r['teklifler'] = $stTk->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($r);
    return $rows;
}

/** @return list<array<string,mixed>> */
function sa_siparis_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM `sa_siparisler` ORDER BY tarih DESC, id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function sa_mal_kabul_listesi(PDO $pdo): array
{
    return $pdo->query(
        'SELECT k.*, s.siparis_no, s.tedarikci_ad, s.toplam_tutar AS po_tutar,
                COALESCE((SELECT SUM(ks.kabul_miktar) FROM `sa_mal_kabul_satirlar` ks WHERE ks.kabul_id = k.id), 0) AS toplam_kabul_miktar
         FROM `sa_mal_kabul` k INNER JOIN `sa_siparisler` s ON s.id = k.siparis_id
         ORDER BY k.tarih DESC, k.id DESC LIMIT 100'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function sa_eslestirme_listesi(PDO $pdo): array
{
    return $pdo->query(
        'SELECT e.*, s.siparis_no, s.tedarikci_ad, k.kabul_no
         FROM `sa_eslestirmeler` e
         INNER JOIN `sa_siparisler` s ON s.id = e.siparis_id
         INNER JOIN `sa_mal_kabul` k ON k.id = e.kabul_id
         ORDER BY e.id DESC LIMIT 100'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function sa_tedarikci_performans(PDO $pdo): array
{
    return $pdo->query(
        'SELECT s.tedarikci_ad,
                COUNT(k.id) AS teslimat_sayisi,
                ROUND(AVG(k.kalite_puani), 2) AS ort_kalite,
                ROUND(AVG(k.zamaninda) * 100, 1) AS zamaninda_yuzde
         FROM `sa_mal_kabul` k
         INNER JOIN `sa_siparisler` s ON s.id = k.siparis_id
         GROUP BY s.tedarikci_ad
         ORDER BY ort_kalite DESC, zamaninda_yuzde DESC LIMIT 50'
    )->fetchAll(PDO::FETCH_ASSOC);
}
