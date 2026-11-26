<?php
// =========================================================
// config/finans_plan_core.php — ERP-10 Bütçe & Sapma, Vade Yaşlandırma (30/60/90/120+ Aging) & Nakit Akış Projeksiyonu
// Include-only (404). Sıfır runtime DDL.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('erp_plan_sayi')) {
    require_once __DIR__ . '/crm_core.php';
}

/** @return list<string> */
function finans_plan_tablolar(): array
{
    return ['fp_butceler', 'fp_nakit_kalemler'];
}

/** @return list<string> */
function finans_plan_eksik_tablolar(PDO $pdo): array
{
    $eksik = [];
    foreach (finans_plan_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $eksik[] = $t;
    }
    return $eksik;
}

function finans_plan_hazir(PDO $pdo): bool
{
    return finans_plan_eksik_tablolar($pdo) === [];
}

function finans_plan_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = finans_plan_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Finansal Planlama şeması kurulu değil: ' . implode(', ', $eksik)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header')) render_header('Bütçe, Nakit Akış & Vade Yaşlandırma');
    if (function_exists('erp_plan_head_varlik')) erp_plan_head_varlik();
    if (function_exists('erp_plan_sema_uyari')) {
        echo erp_plan_sema_uyari($eksik, 'finans-planlama', 'Bütçe, Nakit Akış & Vade Yaşlandırma (ERP-10)');
    }
    if (function_exists('render_footer')) render_footer();
    exit;
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function finans_plan_migrate(PDO $pdo): array
{
    $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $ai  = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'fp_butceler' => "CREATE TABLE IF NOT EXISTS `fp_butceler` (
            `id` $ai,
            `yil` INT NOT NULL,
            `ay` INT NOT NULL,
            `departman` VARCHAR(80) NOT NULL DEFAULT 'GENEL',
            `hesap_kod` VARCHAR(32) NOT NULL DEFAULT '770',
            `tur` VARCHAR(12) NOT NULL DEFAULT 'gider',
            `planlanan_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `gerceklesen_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `aciklama` VARCHAR(300) NOT NULL DEFAULT '',
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(`yil`, `ay`, `departman`, `hesap_kod`)
        )$eng",
        'fp_nakit_kalemler' => "CREATE TABLE IF NOT EXISTS `fp_nakit_kalemler` (
            `id` $ai,
            `vade_tarih` DATE NOT NULL,
            `yon` VARCHAR(10) NOT NULL DEFAULT 'giris',
            `kategori` VARCHAR(40) NOT NULL DEFAULT 'ticari',
            `karsi_taraf` VARCHAR(160) NOT NULL DEFAULT '',
            `tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `durum` VARCHAR(20) NOT NULL DEFAULT 'planli',
            `aciklama` VARCHAR(300) NOT NULL DEFAULT '',
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

// ── 1) Bütçe & Gerçekleşen Sapma Kontrolü ──────────────────────────────────

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function fp_butce_kaydet(PDO $pdo, array $g, int $uid): array
{
    $yil  = (int)($g['yil'] ?? date('Y'));
    $ay   = (int)($g['ay'] ?? date('n'));
    $dept = mb_strtoupper(mb_substr(trim((string)($g['departman'] ?? 'GENEL')), 0, 80, 'UTF-8'), 'UTF-8') ?: 'GENEL';
    $hkod = trim((string)($g['hesap_kod'] ?? '770')) ?: '770';
    $tur  = (string)($g['tur'] ?? (str_starts_with($hkod, '6') ? 'gelir' : 'gider'));
    if (!in_array($tur, ['gelir', 'gider'], true)) $tur = 'gider';
    $plan = max(0.0, round(erp_plan_sayi($g['planlanan_tutar'] ?? 0), 2));
    $gerc = max(0.0, round(erp_plan_sayi($g['gerceklesen_tutar'] ?? 0), 2));
    $ack  = mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 300, 'UTF-8');

    if ($yil < 2000 || $yil > 2099 || $ay < 1 || $ay > 12) return ['ok' => false, 'hata' => 'Geçersiz bütçe yıl/ay bilgisi.', 'kod' => 422];
    if ($plan <= 0.0) return ['ok' => false, 'hata' => 'Planlanan bütçe tutarı sıfırdan büyük olmalıdır.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($yil, $ay, $dept, $hkod, $tur, $plan, $gerc, $ack, $uid): array {
        $chk = $p->prepare('SELECT id FROM `fp_butceler` WHERE yil=? AND ay=? AND departman=? AND hesap_kod=?');
        $chk->execute([$yil, $ay, $dept, $hkod]);
        $ex = $chk->fetchColumn();
        if ($ex) {
            $p->prepare('UPDATE `fp_butceler` SET tur=?, planlanan_tutar=?, gerceklesen_tutar=?, aciklama=? WHERE id=?')
                ->execute([$tur, $plan, $gerc, $ack, (int)$ex]);
            return ['ok' => true, 'id' => (int)$ex, 'mesaj' => "Bütçe kalemi güncellendi ($yil-$ay · $dept / $hkod)."];
        }
        $p->prepare(
            'INSERT INTO `fp_butceler` (yil, ay, departman, hesap_kod, tur, planlanan_tutar, gerceklesen_tutar, aciklama, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$yil, $ay, $dept, $hkod, $tur, $plan, $gerc, $ack, $uid ?: null]);
        return ['ok' => true, 'id' => (int)$p->lastInsertId(), 'mesaj' => "Bütçe kalemi kaydedildi ($yil-$ay · $dept / $hkod)."];
    });
}

/**
 * Bütçe listesini döner; Genel Muhasebe (`muh_fisler` + `muh_fis_satirlar`) kuruluysa ilgili yıl/ay/hesap gerçekleşenini otomatik okur.
 * @return list<array<string,mixed>>
 */
function fp_butce_raporu(PDO $pdo, int $yil): array
{
    $st = $pdo->prepare('SELECT * FROM `fp_butceler` WHERE yil = ? ORDER BY ay ASC, departman ASC, hesap_kod ASC');
    $st->execute([$yil]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $muhVar = db_table_exists($pdo, 'muh_fisler') && db_table_exists($pdo, 'muh_fis_satirlar');
    foreach ($rows as &$r) {
        $gerc = (float)$r['gerceklesen_tutar'];
        if ($muhVar) {
            $bas = sprintf('%04d-%02d-01', (int)$r['yil'], (int)$r['ay']);
            $bit = date('Y-m-t', strtotime($bas));
            $stM = $pdo->prepare(
                "SELECT COALESCE(SUM(CASE WHEN ? = 'gelir' THEN s.alacak - s.borc ELSE s.borc - s.alacak END), 0)
                 FROM `muh_fis_satirlar` s INNER JOIN `muh_fisler` f ON f.id = s.fis_id
                 WHERE f.durum = 'onayli' AND f.tarih BETWEEN ? AND ? AND s.hesap_kod LIKE ?"
            );
            $stM->execute([(string)$r['tur'], $bas, $bit, (string)$r['hesap_kod'] . '%']);
            $muhTut = round(max(0.0, (float)$stM->fetchColumn()), 2);
            if ($muhTut > 0.0) $gerc = $muhTut;
        }
        $plan = (float)$r['planlanan_tutar'];
        $sapma = round($gerc - $plan, 2);
        $oran  = $plan > 0.0 ? round(($gerc / $plan) * 100.0, 1) : 0.0;
        $r['gerceklesen_etkin'] = $gerc;
        $r['sapma_tutar'] = $sapma;
        $r['gerceklesme_yuzde'] = $oran;
        $r['asim'] = ((string)$r['tur'] === 'gider' && $gerc > $plan);
    }
    unset($r);
    return $rows;
}

// ── 2) Planlı Nakit Kalemi ──────────────────────────────────────────────────

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function fp_nakit_kalem_kaydet(PDO $pdo, array $g, int $uid): array
{
    $vade  = trim((string)($g['vade_tarih'] ?? date('Y-m-d')));
    $yon   = (string)($g['yon'] ?? 'giris');
    if (!in_array($yon, ['giris', 'cikis'], true)) $yon = 'giris';
    $kat   = mb_substr(trim((string)($g['kategori'] ?? 'ticari')), 0, 40, 'UTF-8') ?: 'ticari';
    $karsi = mb_substr(trim((string)($g['karsi_taraf'] ?? '')), 0, 160, 'UTF-8');
    $tutar = round(erp_plan_sayi($g['tutar'] ?? 0), 2);
    $pb    = strtoupper(trim((string)($g['para_birimi'] ?? 'TRY')));
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';
    $ack   = mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 300, 'UTF-8');

    if (!erp_plan_tarih_gecerli($vade)) return ['ok' => false, 'hata' => 'Geçersiz vade tarihi.', 'kod' => 422];
    if ($tutar <= 0.0) return ['ok' => false, 'hata' => 'Tutar sıfırdan büyük olmalıdır.', 'kod' => 422];

    $pdo->prepare(
        "INSERT INTO `fp_nakit_kalemler` (vade_tarih, yon, kategori, karsi_taraf, tutar, para_birimi, durum, aciklama, olusturan_id)
         VALUES (?, ?, ?, ?, ?, ?, 'planli', ?, ?)"
    )->execute([$vade, $yon, $kat, $karsi, $tutar, $pb, $ack, $uid ?: null]);
    return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'mesaj' => 'Planlı nakit akış kalemi kaydedildi.'];
}

// ── 3) Konsolide Vade Yaşlandırma (30 / 60 / 90 / 120+ Gün Aging) ──────────

/**
 * Fatura, kredi taksiti ve planlı nakit kalemlerini 30/60/90/120+ gün kovalarına dağıtır.
 * @return array{alacak:array<string,float>,borc:array<string,float>,net_toplam:float,kalemler:list<array<string,mixed>>}
 */
function fp_vade_yaslandirma(PDO $pdo, ?string $refTarih = null): array
{
    $ref = $refTarih ?? date('Y-m-d');
    $refTs = strtotime($ref);
    $bosKova = ['guncel' => 0.0, 'g0_30' => 0.0, 'g31_60' => 0.0, 'g61_90' => 0.0, 'g91_120' => 0.0, 'g120_plus' => 0.0, 'toplam' => 0.0];
    $alacak = $bosKova;
    $borc   = $bosKova;
    $kalemler = [];

    $ekle = static function (string $tip, string $kaynak, string $taraf, string $vade, float $tutar) use ($refTs, &$alacak, &$borc, &$kalemler): void {
        if ($tutar <= 0.0 || !erp_plan_tarih_gecerli($vade)) return;
        $gecikme = (int)floor(($refTs - strtotime($vade)) / 86400);
        if ($gecikme <= 0) $kova = 'guncel';
        elseif ($gecikme <= 30) $kova = 'g0_30';
        elseif ($gecikme <= 60) $kova = 'g31_60';
        elseif ($gecikme <= 90) $kova = 'g61_90';
        elseif ($gecikme <= 120) $kova = 'g91_120';
        else $kova = 'g120_plus';

        if ($tip === 'alacak') {
            $alacak[$kova] = round($alacak[$kova] + $tutar, 2);
            $alacak['toplam'] = round($alacak['toplam'] + $tutar, 2);
        } else {
            $borc[$kova] = round($borc[$kova] + $tutar, 2);
            $borc['toplam'] = round($borc['toplam'] + $tutar, 2);
        }
        $kalemler[] = [
            'tip'         => $tip,
            'kaynak'      => $kaynak,
            'karsi_taraf' => $taraf,
            'vade_tarih'  => $vade,
            'gecikme_gun' => max(0, $gecikme),
            'kova'        => $kova,
            'tutar'       => round($tutar, 2),
        ];
    };

    // 1) Planlı nakit kalemleri
    if (db_table_exists($pdo, 'fp_nakit_kalemler')) {
        foreach ($pdo->query("SELECT * FROM `fp_nakit_kalemler` WHERE durum = 'planli' ORDER BY vade_tarih ASC")->fetchAll(PDO::FETCH_ASSOC) as $nk) {
            $ekle((string)$nk['yon'] === 'giris' ? 'alacak' : 'borc', 'Planlı (' . $nk['kategori'] . ')', (string)$nk['karsi_taraf'], (string)$nk['vade_tarih'], (float)$nk['tutar']);
        }
    }

    // 2) Banka kredi bekleyen taksitleri (borç)
    if (db_table_exists($pdo, 'banka_kredi_taksitler') && db_table_exists($pdo, 'banka_krediler')) {
        foreach ($pdo->query("SELECT t.vade_tarih, t.toplam_taksit, k.sozlesme_no, k.banka_ad FROM `banka_kredi_taksitler` t INNER JOIN `banka_krediler` k ON k.id = t.kredi_id WHERE t.durum = 'bekliyor'")->fetchAll(PDO::FETCH_ASSOC) as $kt) {
            $ekle('borc', 'Kredi ' . $kt['sozlesme_no'], (string)$kt['banka_ad'], (string)$kt['vade_tarih'], (float)$kt['toplam_taksit']);
        }
    }

    // 3) Faturalar (vade_tarihi varsa onu yoksa tarih + 30 gün baz alır)
    if (db_table_exists($pdo, 'fatura_belgeler')) {
        $cols = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? array_column($pdo->query('PRAGMA table_info(`fatura_belgeler`)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $pdo->query('SHOW COLUMNS FROM `fatura_belgeler`')->fetchAll(PDO::FETCH_COLUMN);
        $vadeExpr = in_array('vade_tarihi', $cols, true) ? 'COALESCE(vade_tarihi, tarih)' : 'tarih';
        foreach ($pdo->query("SELECT tur, belge_no, $vadeExpr AS vade, genel_toplam FROM `fatura_belgeler` WHERE durum = 'gecerli' LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) as $ft) {
            $tip = in_array((string)$ft['tur'], ['satis', 'satis_ihracat'], true) ? 'alacak' : 'borc';
            $ekle($tip, 'Fatura ' . $ft['belge_no'], (string)$ft['belge_no'], (string)$ft['vade'], (float)$ft['genel_toplam']);
        }
    }

    return [
        'alacak'     => $alacak,
        'borc'       => $borc,
        'net_toplam' => round($alacak['toplam'] - $borc['toplam'], 2),
        'kalemler'   => $kalemler,
    ];
}

// ── 4) Nakit Akış Projeksiyonu (30 / 60 / 90 Gün — Haftalık Kümülasyon) ────

/**
 * Önümüzdeki N gün boyunca haftalık nakit giriş/çıkış ve kümülatif bakiye projeksiyonu üretir.
 * @return array{acilis_nakit:float,haftalar:list<array<string,mixed>>,en_dusuk_bakiye:float,negatif_risk:bool}
 */
function fp_nakit_akis_projeksiyon(PDO $pdo, ?string $basTarih = null, int $gunSayisi = 90): array
{
    $bas = $basTarih ?? date('Y-m-d');
    $gunSayisi = max(7, min(365, $gunSayisi));
    $basTs = strtotime($bas);
    $bit = date('Y-m-d', strtotime("+$gunSayisi days", $basTs));

    // Açılış nakit pozisyonu (banka hesapları bakiyesi)
    $acilis = 0.0;
    if (db_table_exists($pdo, 'banka_hesaplari')) {
        $ekSql = db_table_exists($pdo, 'banka_ekstreler')
            ? 'COALESCE((SELECT SUM(e.tutar) FROM `banka_ekstreler` e WHERE e.hesap_id = h.id), 0)'
            : '0';
        $acilis = round((float)$pdo->query("SELECT COALESCE(SUM(h.acilis_bakiye + $ekSql), 0) FROM `banka_hesaplari` h WHERE h.aktif = 1")->fetchColumn(), 2);
    }

    $olaylar = [];
    if (db_table_exists($pdo, 'fp_nakit_kalemler')) {
        $st = $pdo->prepare("SELECT vade_tarih, yon, tutar FROM `fp_nakit_kalemler` WHERE durum = 'planli' AND vade_tarih BETWEEN ? AND ?");
        $st->execute([$bas, $bit]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $olaylar[] = ['tarih' => (string)$r['vade_tarih'], 'giris' => $r['yon'] === 'giris' ? (float)$r['tutar'] : 0.0, 'cikis' => $r['yon'] === 'cikis' ? (float)$r['tutar'] : 0.0];
        }
    }
    if (db_table_exists($pdo, 'banka_kredi_taksitler')) {
        $st = $pdo->prepare("SELECT vade_tarih, toplam_taksit FROM `banka_kredi_taksitler` WHERE durum = 'bekliyor' AND vade_tarih BETWEEN ? AND ?");
        $st->execute([$bas, $bit]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $olaylar[] = ['tarih' => (string)$r['vade_tarih'], 'giris' => 0.0, 'cikis' => (float)$r['toplam_taksit']];
        }
    }
    // İK özlük kuruluysa her ay sonuna tahmini personel maaş çıkışı ekle
    if (db_table_exists($pdo, 'ik_ozluk')) {
        $aylikMaas = round((float)$pdo->query('SELECT COALESCE(SUM(brut_maas), 0) FROM `ik_ozluk` WHERE aktif = 1')->fetchColumn(), 2);
        if ($aylikMaas > 0.0) {
            $cursor = strtotime(date('Y-m-01', $basTs));
            $bitTs  = strtotime($bit);
            while ($cursor <= $bitTs) {
                $aySonu = date('Y-m-t', $cursor);
                if ($aySonu >= $bas && $aySonu <= $bit) {
                    $olaylar[] = ['tarih' => $aySonu, 'giris' => 0.0, 'cikis' => $aylikMaas];
                }
                $cursor = strtotime('+1 month', $cursor);
            }
        }
    }

    $haftaSay = (int)ceil($gunSayisi / 7);
    $haftalar = [];
    $bakiye = $acilis;
    $minBak = $acilis;
    for ($w = 0; $w < $haftaSay; $w++) {
        $hBas = date('Y-m-d', strtotime('+' . ($w * 7) . ' days', $basTs));
        $hBit = date('Y-m-d', strtotime('+' . min($gunSayisi, ($w + 1) * 7 - 1) . ' days', $basTs));
        $gTop = 0.0; $cTop = 0.0;
        foreach ($olaylar as $o) {
            if ($o['tarih'] >= $hBas && $o['tarih'] <= $hBit) {
                $gTop = round($gTop + $o['giris'], 2);
                $cTop = round($cTop + $o['cikis'], 2);
            }
        }
        $net = round($gTop - $cTop, 2);
        $bakiye = round($bakiye + $net, 2);
        if ($bakiye < $minBak) $minBak = $bakiye;
        $haftalar[] = [
            'hafta_no'  => $w + 1,
            'bas_tarih' => $hBas,
            'bit_tarih' => $hBit,
            'giris'     => $gTop,
            'cikis'     => $cTop,
            'net'       => $net,
            'kumulatif' => $bakiye,
        ];
    }

    return [
        'acilis_nakit'    => $acilis,
        'haftalar'        => $haftalar,
        'en_dusuk_bakiye' => $minBak,
        'negatif_risk'    => $minBak < 0.0,
    ];
}

/** @return list<array<string,mixed>> */
function fp_nakit_kalem_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM `fp_nakit_kalemler` ORDER BY vade_tarih ASC, id DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC);
}
