<?php
// =========================================================
// config/uretim_bom_core.php — ERP-4 Üretim Reçetesi (BOM), İş Emirleri, Fire/Randıman, Lot-to-Lot İzlenebilirlik & MRP
//
// Mevcut gunluk_uretim.php (işçi/kg verim raporu) bozulmadan bağımsız ERP üretim motoru:
//   - uretim_bom + uretim_bom_satirlar  : Ürün Ağacı (hammadde + ambalaj/kasa/palet sarf katsayıları + beklenen fire %)
//   - uretim_is_emirleri                : İş Emri (IE-YYYY-00001), planlanan/gerçekleşen/çıkma/fire, randıman %
//   - uretim_is_emri_sarf               : İş emri bazlı gerçek hammadde/ambalaj sarf satırları
//   - uretim_lot_iz                     : Lot-to-Lot İzlenebilirlik (kaynak hammadde lotu → iş emri → mamul lotu)
//   - uretim_mrp_hesapla()              : Açık iş emirleri BOM patlatması vs WMS mevcut stok + açık satın alma → Net Eksik
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/wms_stok_core.php';

/** @return list<string> */
function uretim_bom_tablolar(): array
{
    return ['uretim_bom', 'uretim_bom_satirlar', 'uretim_is_emirleri', 'uretim_is_emri_sarf', 'uretim_lot_iz'];
}

/** @return list<string> */
function uretim_bom_eksik_tablolar(PDO $pdo): array
{
    $e = [];
    foreach (uretim_bom_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $e[] = $t;
    }
    return $e;
}

function uretim_bom_hazir(PDO $pdo): bool
{
    return uretim_bom_eksik_tablolar($pdo) === [];
}

function uretim_bom_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = uretim_bom_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Üretim BOM/MRP tabloları kurulmamış (' . implode(', ', $eksik) . '). migrate.php#uretim-bom kartını çalıştırın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header') && function_exists('erp_op_sema_uyari')) {
        render_header('Üretim BOM, İş Emirleri & MRP');
        echo erp_op_sema_uyari($eksik, 'uretim-bom', 'Üretim Reçetesi (BOM), İş Emirleri & MRP (ERP-4)');
        if (function_exists('render_footer')) render_footer();
        exit;
    }
    http_response_code(503);
    exit('Üretim BOM şeması kurulmamış.');
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function uretim_bom_migrate(PDO $pdo): array
{
    $sqlite = db_is_sqlite($pdo);
    $ai  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'uretim_bom' => "CREATE TABLE IF NOT EXISTS `uretim_bom` (
            `id` $ai,
            `kod` VARCHAR(40) NOT NULL UNIQUE,
            `ad` VARCHAR(180) NOT NULL,
            `urun_stok_id` INT NOT NULL,
            `baz_miktar` DECIMAL(14,3) NOT NULL DEFAULT 1.000,
            `birim` VARCHAR(20) NOT NULL DEFAULT 'kg',
            `hedef_fire_yuzde` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `aktif` TINYINT NOT NULL DEFAULT 1,
            `notlar` VARCHAR(400) NOT NULL DEFAULT '',
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'uretim_bom_satirlar' => "CREATE TABLE IF NOT EXISTS `uretim_bom_satirlar` (
            `id` $ai,
            `bom_id` INT NOT NULL,
            `bilesen_stok_id` INT NOT NULL,
            `bilesen_ad` VARCHAR(180) NOT NULL DEFAULT '',
            `bilesen_tip` VARCHAR(20) NOT NULL DEFAULT 'hammadde',
            `miktar` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
            `birim` VARCHAR(20) NOT NULL DEFAULT 'kg',
            `fire_yuzde` DECIMAL(6,2) NOT NULL DEFAULT 0.00
        )$eng",
        'uretim_is_emirleri' => "CREATE TABLE IF NOT EXISTS `uretim_is_emirleri` (
            `id` $ai,
            `emir_no` VARCHAR(40) NOT NULL UNIQUE,
            `bom_id` INT NOT NULL,
            `urun_stok_id` INT NOT NULL,
            `depo` VARCHAR(120) NOT NULL DEFAULT 'MERKEZ',
            `tarih` DATE NOT NULL,
            `termin_tarih` DATE NULL,
            `planlanan_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `uretilen_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `cikma_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `fire_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `randiman_yuzde` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `toplam_sarf_maliyet` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `birim_maliyet` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
            `uretim_lot_no` VARCHAR(60) NOT NULL DEFAULT '',
            `durum` VARCHAR(20) NOT NULL DEFAULT 'planlandi',
            `aciklama` VARCHAR(400) NOT NULL DEFAULT '',
            `olusturan_id` INT NULL,
            `tamamlandi_at` DATETIME NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'uretim_is_emri_sarf' => "CREATE TABLE IF NOT EXISTS `uretim_is_emri_sarf` (
            `id` $ai,
            `is_emri_id` INT NOT NULL,
            `bilesen_stok_id` INT NOT NULL,
            `bilesen_ad` VARCHAR(180) NOT NULL DEFAULT '',
            `bilesen_tip` VARCHAR(20) NOT NULL DEFAULT 'hammadde',
            `planlanan_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `gerceklesen_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `maliyet_tl` DECIMAL(16,2) NOT NULL DEFAULT 0.00
        )$eng",
        'uretim_lot_iz' => "CREATE TABLE IF NOT EXISTS `uretim_lot_iz` (
            `id` $ai,
            `is_emri_id` INT NOT NULL,
            `kaynak_lot_id` INT NOT NULL,
            `kaynak_lot_no` VARCHAR(60) NOT NULL,
            `kaynak_stok_id` INT NOT NULL,
            `tuketilen_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `hedef_lot_id` INT NULL,
            `hedef_lot_no` VARCHAR(60) NOT NULL,
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

// ── 1) Ürün Ağacı (BOM) ────────────────────────────────────────────────────

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function uretim_bom_kaydet(PDO $pdo, array $g, int $uid): array
{
    $kod     = mb_strtoupper(mb_substr(trim((string)($g['kod'] ?? '')), 0, 40, 'UTF-8'), 'UTF-8');
    $ad      = mb_substr(trim((string)($g['ad'] ?? '')), 0, 180, 'UTF-8');
    $urunId  = (int)($g['urun_stok_id'] ?? 0);
    $baz     = max(0.001, round(erp_op_sayi($g['baz_miktar'] ?? 1.0), 3));
    $birim   = mb_substr(trim((string)($g['birim'] ?? 'kg')), 0, 20, 'UTF-8');
    $fire    = max(0.0, min(90.0, round(erp_op_sayi($g['hedef_fire_yuzde'] ?? 0), 2)));
    $notlar  = mb_substr(trim((string)($g['notlar'] ?? '')), 0, 400, 'UTF-8');
    $satirlar = is_array($g['satirlar'] ?? null) ? $g['satirlar'] : [];

    if ($kod === '') $kod = 'BOM-' . $urunId . '-' . date('His');
    if ($ad === '') return ['ok' => false, 'hata' => 'Reçete (BOM) adı zorunludur.', 'kod' => 422];
    if ($urunId <= 0) return ['ok' => false, 'hata' => 'Üretilecek mamul stok kartını seçin.', 'kod' => 422];
    if ($satirlar === []) return ['ok' => false, 'hata' => 'Reçeteye en az bir hammadde/ambalaj bileşeni eklemelisiniz.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($kod, $ad, $urunId, $baz, $birim, $fire, $notlar, $satirlar, $uid): array {
        $chk = $p->prepare('SELECT id FROM `uretim_bom` WHERE kod = ?');
        $chk->execute([$kod]);
        if ($chk->fetchColumn()) return ['ok' => false, 'hata' => "Bu BOM kodu zaten mevcut ($kod).", 'kod' => 409];

        $p->prepare(
            'INSERT INTO `uretim_bom` (kod, ad, urun_stok_id, baz_miktar, birim, hedef_fire_yuzde, aktif, notlar, olusturan_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([$kod, $ad, $urunId, $baz, $birim, $fire, $notlar, $uid ?: null]);
        $bomId = (int)$p->lastInsertId();

        $eklenen = 0;
        foreach ($satirlar as $s) {
            $bid = (int)($s['bilesen_stok_id'] ?? 0);
            $m   = round(erp_op_sayi($s['miktar'] ?? 0), 4);
            if ($bid <= 0 || $m <= 0.0) continue;
            if ($bid === $urunId) return ['ok' => false, 'hata' => 'Mamul kendi kendisinin bileşeni olamaz (döngüsel BOM).', 'kod' => 422];
            $bTip = (string)($s['bilesen_tip'] ?? 'hammadde');
            if (!in_array($bTip, ['hammadde', 'ambalaj', 'yardimci'], true)) $bTip = 'hammadde';
            $bAd = mb_substr(trim((string)($s['bilesen_ad'] ?? '')), 0, 180, 'UTF-8');
            $bBirim = mb_substr(trim((string)($s['birim'] ?? 'kg')), 0, 20, 'UTF-8');
            $bFire = max(0.0, min(90.0, round(erp_op_sayi($s['fire_yuzde'] ?? 0), 2)));
            $p->prepare('INSERT INTO `uretim_bom_satirlar` (bom_id, bilesen_stok_id, bilesen_ad, bilesen_tip, miktar, birim, fire_yuzde) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$bomId, $bid, $bAd, $bTip, $m, $bBirim, $bFire]);
            $eklenen++;
        }
        if ($eklenen === 0) throw new RuntimeException('Geçerli BOM bileşeni yok.');
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'uretim_bom', $bomId, null, ['kod' => $kod, 'urun_stok_id' => $urunId, 'bilesen' => $eklenen]);
        }
        return ['ok' => true, 'id' => $bomId, 'mesaj' => "Üretim reçetesi kaydedildi ($kod)."];
    });
}

// ── 2) Üretim İş Emri Açma & Tamamlama (FIFO Sarf + Fire/Randıman + Lot-to-Lot) ──

/** @return array{ok:bool,id?:int,emir_no?:string,hata?:string,mesaj?:string,kod?:int} */
function uretim_is_emri_ac(PDO $pdo, array $g, int $uid): array
{
    $bomId  = (int)($g['bom_id'] ?? 0);
    $tarih  = trim((string)($g['tarih'] ?? date('Y-m-d')));
    $termin = trim((string)($g['termin_tarih'] ?? ''));
    $depo   = mb_strtoupper(mb_substr(trim((string)($g['depo'] ?? 'MERKEZ')), 0, 120, 'UTF-8'), 'UTF-8');
    if ($depo === '') $depo = 'MERKEZ';
    $miktar = round(erp_op_sayi($g['planlanan_miktar'] ?? 0), 3);
    $ack    = mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 400, 'UTF-8');

    if ($bomId <= 0) return ['ok' => false, 'hata' => 'Geçerli bir üretim reçetesi (BOM) seçin.', 'kod' => 422];
    if ($miktar <= 0.0) return ['ok' => false, 'hata' => 'Planlanan üretim miktarı sıfırdan büyük olmalıdır.', 'kod' => 422];
    if (!erp_op_tarih_gecerli($tarih)) return ['ok' => false, 'hata' => 'Geçersiz iş emri tarihi.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($bomId, $tarih, $termin, $depo, $miktar, $ack, $uid): array {
        $stB = $p->prepare('SELECT * FROM `uretim_bom` WHERE id = ?');
        $stB->execute([$bomId]);
        $bom = $stB->fetch(PDO::FETCH_ASSOC);
        if (!$bom) return ['ok' => false, 'hata' => 'Reçete (BOM) bulunamadı.', 'kod' => 404];

        $yil = substr($tarih, 0, 4);
        $stN = $p->prepare('SELECT COUNT(*) FROM `uretim_is_emirleri` WHERE emir_no LIKE ?');
        $stN->execute(["IE-$yil-%"]);
        $sira = (int)$stN->fetchColumn() + 1;
        $emirNo = sprintf('IE-%s-%05d', $yil, $sira);
        while (true) {
            $c = $p->prepare('SELECT 1 FROM `uretim_is_emirleri` WHERE emir_no = ?');
            $c->execute([$emirNo]);
            if (!$c->fetchColumn()) break;
            $sira++;
            $emirNo = sprintf('IE-%s-%05d', $yil, $sira);
        }

        $lotNo = 'URT-' . str_replace('-', '', $tarih) . '-' . str_pad((string)$sira, 4, '0', STR_PAD_LEFT);
        $p->prepare(
            "INSERT INTO `uretim_is_emirleri` (emir_no, bom_id, urun_stok_id, depo, tarih, termin_tarih, planlanan_miktar, uretim_lot_no, durum, aciklama, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'planlandi', ?, ?)"
        )->execute([$emirNo, $bomId, (int)$bom['urun_stok_id'], $depo, $tarih, $termin !== '' ? $termin : null, $miktar, $lotNo, $ack, $uid ?: null]);
        $ieId = (int)$p->lastInsertId();

        // BOM patlatması → planlanan sarf satırlarını oluştur
        $katsayi = $miktar / max(0.001, (float)$bom['baz_miktar']);
        $stSat = $p->prepare('SELECT * FROM `uretim_bom_satirlar` WHERE bom_id = ?');
        $stSat->execute([$bomId]);
        foreach ($stSat->fetchAll(PDO::FETCH_ASSOC) as $bs) {
            $fireCarp = 1.0 + ((float)$bs['fire_yuzde'] / 100.0);
            $planSarf = round((float)$bs['miktar'] * $katsayi * $fireCarp, 3);
            $p->prepare(
                'INSERT INTO `uretim_is_emri_sarf` (is_emri_id, bilesen_stok_id, bilesen_ad, bilesen_tip, planlanan_miktar, gerceklesen_miktar, maliyet_tl) VALUES (?, ?, ?, ?, ?, 0, 0)'
            )->execute([$ieId, (int)$bs['bilesen_stok_id'], (string)$bs['bilesen_ad'], (string)$bs['bilesen_tip'], $planSarf]);
        }

        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'uretim_is_emri', $ieId, null, ['emir_no' => $emirNo, 'planlanan' => $miktar, 'lot' => $lotNo]);
        }
        return ['ok' => true, 'id' => $ieId, 'emir_no' => $emirNo, 'uretim_lot_no' => $lotNo, 'mesaj' => "İş emri açıldı ($emirNo · Lot: $lotNo)."];
    });
}

/**
 * İş emrini tamamlar:
 *   1) Hammadde ve ambalaj bileşenlerini WMS'ten FIFO düşer + maliyetini toplar
 *   2) Gerçek fire ve randıman (%) hesaplar
 *   3) Üretilen mamul lotunu hesaplanan birim maliyetle WMS'e girer
 *   4) Lot-to-Lot izlenebilirlik (kaynak hammadde lotları → iş emri → mamul lotu) kaydını yazar
 * @return array{ok:bool,randiman_yuzde?:float,birim_maliyet?:float,hedef_lot_id?:int,hata?:string,mesaj?:string,kod?:int}
 */
function uretim_is_emri_tamamla(PDO $pdo, int $ieId, array $g, int $uid): array
{
    $uretilen = max(0.0, round(erp_op_sayi($g['uretilen_miktar'] ?? 0), 3));
    $cikma    = max(0.0, round(erp_op_sayi($g['cikma_miktar'] ?? 0), 3));
    $fire     = max(0.0, round(erp_op_sayi($g['fire_miktar'] ?? 0), 3));
    $sktTar   = trim((string)($g['skt_tarih'] ?? ''));

    if ($uretilen <= 0.0) return ['ok' => false, 'hata' => 'Üretilen (sağlam) miktar sıfırdan büyük olmalıdır.', 'kod' => 422];
    if ($sktTar !== '' && !erp_op_tarih_gecerli($sktTar)) return ['ok' => false, 'hata' => 'Geçersiz mamul SKT tarihi.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($ieId, $uretilen, $cikma, $fire, $sktTar, $uid): array {
        $st = $p->prepare('SELECT * FROM `uretim_is_emirleri` WHERE id = ?' . db_for_update($p));
        $st->execute([$ieId]);
        $ie = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ie) return ['ok' => false, 'hata' => 'İş emri bulunamadı.', 'kod' => 404];
        if ((string)$ie['durum'] === 'tamamlandi') return ['ok' => false, 'hata' => 'Bu iş emri zaten tamamlanmış.', 'kod' => 409];

        $stSarf = $p->prepare('SELECT * FROM `uretim_is_emri_sarf` WHERE is_emri_id = ?');
        $stSarf->execute([$ieId]);
        $sarflar = $stSarf->fetchAll(PDO::FETCH_ASSOC);

        $wmsHazir = wms_stok_hazir($p);
        $topMaliyet = 0.0;
        $izKayitlari = [];

        foreach ($sarflar as $sf) {
            $sarfMik = (float)$sf['planlanan_miktar'];
            $sarfMal = 0.0;
            if ($wmsHazir && $sarfMik > 0) {
                $tuket = wms_lot_tuket_fifo($p, (int)$sf['bilesen_stok_id'], $sarfMik, (string)$ie['depo'], false);
                if (empty($tuket['ok'])) {
                    return ['ok' => false, 'hata' => 'Bileşen #' . $sf['bilesen_stok_id'] . ' (' . $sf['bilesen_ad'] . '): ' . ($tuket['hata'] ?? 'yetersiz stok'), 'kod' => 409];
                }
                $sarfMal = (float)$tuket['toplam_maliyet'];
                foreach ($tuket['lotlar'] as $tl) {
                    $izKayitlari[] = [
                        'kaynak_lot_id'   => $tl['lot_id'],
                        'kaynak_lot_no'   => $tl['lot_no'],
                        'kaynak_stok_id'  => (int)$sf['bilesen_stok_id'],
                        'tuketilen_miktar'=> $tl['miktar'],
                    ];
                }
            }
            $topMaliyet = round($topMaliyet + $sarfMal, 2);
            $p->prepare('UPDATE `uretim_is_emri_sarf` SET gerceklesen_miktar = ?, maliyet_tl = ? WHERE id = ?')
                ->execute([$sarfMik, $sarfMal, (int)$sf['id']]);
        }

        $toplamCikti = round($uretilen + $cikma + $fire, 3);
        $randiman = $toplamCikti > 0 ? round(($uretilen / $toplamCikti) * 100.0, 2) : 100.0;
        $birimMal = $uretilen > 0 ? round($topMaliyet / $uretilen, 4) : 0.0;

        // Mamul lotunu WMS'e kaydet
        $hedefLotId = null;
        if ($wmsHazir) {
            $lg = wms_lot_giris($p, [
                'stok_id'       => (int)$ie['urun_stok_id'],
                'depo'          => (string)$ie['depo'],
                'lot_no'        => (string)$ie['uretim_lot_no'],
                'uretim_tarih'  => date('Y-m-d'),
                'skt_tarih'     => $sktTar,
                'miktar'        => $uretilen,
                'birim_maliyet' => $birimMal,
                'para_birimi'   => 'TRY',
                'kaynak_modul'  => 'uretim_is_emri',
                'kaynak_id'     => $ieId,
            ], $uid);
            if (!empty($lg['ok'])) $hedefLotId = (int)$lg['id'];
        }

        // Lot-to-Lot izlenebilirlik bağlarını yaz
        foreach ($izKayitlari as $iz) {
            $p->prepare(
                'INSERT INTO `uretim_lot_iz` (is_emri_id, kaynak_lot_id, kaynak_lot_no, kaynak_stok_id, tuketilen_miktar, hedef_lot_id, hedef_lot_no)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$ieId, $iz['kaynak_lot_id'], $iz['kaynak_lot_no'], $iz['kaynak_stok_id'], $iz['tuketilen_miktar'], $hedefLotId, (string)$ie['uretim_lot_no']]);
        }

        $p->prepare(
            "UPDATE `uretim_is_emirleri`
             SET uretilen_miktar=?, cikma_miktar=?, fire_miktar=?, randiman_yuzde=?, toplam_sarf_maliyet=?, birim_maliyet=?, durum='tamamlandi', tamamlandi_at=?
             WHERE id=?"
        )->execute([$uretilen, $cikma, $fire, $randiman, $topMaliyet, $birimMal, date('Y-m-d H:i:s'), $ieId]);

        if (function_exists('audit_log_event')) {
            audit_log_event('complete', 'uretim_is_emri', $ieId, ['durum' => $ie['durum']], [
                'durum' => 'tamamlandi', 'uretilen' => $uretilen, 'fire' => $fire, 'randiman' => $randiman, 'birim_maliyet' => $birimMal,
            ]);
        }
        return [
            'ok'            => true,
            'randiman_yuzde'=> $randiman,
            'birim_maliyet' => $birimMal,
            'hedef_lot_id'  => $hedefLotId,
            'mesaj'         => sprintf('%s tamamlandı (Randıman: %%%.2f · Birim Maliyet: %.4f TL · Lot: %s).', $ie['emir_no'], $randiman, $birimMal, $ie['uretim_lot_no']),
        ];
    });
}

// ── 3) Temel MRP Motoru (Brüt İhtiyaç vs Mevcut WMS Stok + Açık Satın Alma) ──

/**
 * Açık/Planlı iş emirlerinin bileşen ihtiyaçlarını toplar, WMS mevcut stoğu ve açık satın alma siparişleriyle karşılaştırıp Net Eksik listesi üretir.
 * @return list<array{stok_id:int,bilesen_ad:string,bilesen_tip:string,brut_ihtiyac:float,mevcut_stok:float,acik_siparis:float,net_eksik:float}>
 */
function uretim_mrp_hesapla(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT s.bilesen_stok_id, MAX(s.bilesen_ad) AS bilesen_ad, MAX(s.bilesen_tip) AS bilesen_tip,
                ROUND(SUM(s.planlanan_miktar), 3) AS brut_ihtiyac
         FROM `uretim_is_emri_sarf` s
         INNER JOIN `uretim_is_emirleri` e ON e.id = s.is_emri_id
         WHERE e.durum IN ('planlandi', 'devam')
         GROUP BY s.bilesen_stok_id
         ORDER BY brut_ihtiyac DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $wmsVar = wms_stok_hazir($pdo);
    $saVar  = db_table_exists($pdo, 'sa_siparis_satirlar') && db_table_exists($pdo, 'sa_siparisler');
    $out = [];

    foreach ($rows as $r) {
        $sid = (int)$r['bilesen_stok_id'];
        $brut = round((float)$r['brut_ihtiyac'], 3);
        $mevcut = 0.0;
        if ($wmsVar) {
            $stM = $pdo->prepare("SELECT COALESCE(SUM(kalan_miktar), 0) FROM `wms_lotlar` WHERE stok_id = ? AND durum = 'aktif'");
            $stM->execute([$sid]);
            $mevcut = round((float)$stM->fetchColumn(), 3);
        }
        $acikSa = 0.0;
        if ($saVar) {
            $stA = $pdo->prepare(
                "SELECT COALESCE(SUM(CASE WHEN ss.siparis_miktar > ss.teslim_miktar THEN ss.siparis_miktar - ss.teslim_miktar ELSE 0 END), 0)
                 FROM `sa_siparis_satirlar` ss
                 INNER JOIN `sa_siparisler` sp ON sp.id = ss.siparis_id
                 WHERE ss.stok_id = ? AND sp.durum IN ('acik', 'kismi_teslim')"
            );
            $stA->execute([$sid]);
            $acikSa = round((float)$stA->fetchColumn(), 3);
        }
        $eksik = round(max(0.0, $brut - ($mevcut + $acikSa)), 3);
        $out[] = [
            'stok_id'      => $sid,
            'bilesen_ad'   => (string)$r['bilesen_ad'] ?: ('Stok #' . $sid),
            'bilesen_tip'  => (string)$r['bilesen_tip'],
            'brut_ihtiyac' => $brut,
            'mevcut_stok'  => $mevcut,
            'acik_siparis' => $acikSa,
            'net_eksik'    => $eksik,
        ];
    }
    return $out;
}

// ── Okuyucu Listeler ───────────────────────────────────────────────────────

/** @return list<array<string,mixed>> */
function uretim_bom_listesi(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM `uretim_bom` ORDER BY aktif DESC, kod ASC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $st = $pdo->prepare('SELECT * FROM `uretim_bom_satirlar` WHERE bom_id = ? ORDER BY id ASC');
        $st->execute([(int)$r['id']]);
        $r['satirlar'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($r);
    return $rows;
}

/** @return list<array<string,mixed>> */
function uretim_is_emri_listesi(PDO $pdo): array
{
    return $pdo->query(
        'SELECT e.*, b.kod AS bom_kod, b.ad AS bom_ad
         FROM `uretim_is_emirleri` e
         INNER JOIN `uretim_bom` b ON b.id = e.bom_id
         ORDER BY e.tarih DESC, e.id DESC LIMIT 100'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function uretim_lot_iz_listesi(PDO $pdo, string $lotAra = ''): array
{
    $w = '1=1'; $p = [];
    if (trim($lotAra) !== '') {
        $q = '%' . trim($lotAra) . '%';
        $w = '(i.kaynak_lot_no LIKE ? OR i.hedef_lot_no LIKE ?)';
        $p = [$q, $q];
    }
    $st = $pdo->prepare(
        "SELECT i.*, e.emir_no, e.tarih AS uretim_tarih
         FROM `uretim_lot_iz` i
         INNER JOIN `uretim_is_emirleri` e ON e.id = i.is_emri_id
         WHERE $w
         ORDER BY i.id DESC LIMIT 200"
    );
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
