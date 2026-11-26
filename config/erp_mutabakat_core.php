<?php
// =========================================================
// config/erp_mutabakat_core.php — ERP-11a Cari Risk Limiti, BA/BS & Dönem Sonu Kur Farkı
//
// Mevcut cari_core.php ve doviz_core.php izolasyonunu bozmadan çalışan tamamlayıcı motor:
//   1) Cari Kredi / Risk Limiti tanımlama ve anlık bakiye aşım / blokaj kontrolü
//   2) Aylık Form BA (Alış) / Form BS (Satış) mutabakat listesi (fatura_belgeler)
//   3) Dönem Sonu Döviz Kur Farkı Değerleme (646 Kambiyo Kârları / 656 Kambiyo Zararları → muh_fisler)
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db_tx_helpers.php';
require_once __DIR__ . '/muhasebe_core.php';

/** @return list<string> */
function erp_mutabakat_tablolar(): array
{
    return ['cari_kredi_limitleri', 'kur_farki_degerlemeler'];
}

/** @return list<string> */
function erp_mutabakat_eksik_tablolar(PDO $pdo): array
{
    $e = [];
    foreach (erp_mutabakat_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $e[] = $t;
    }
    return $e;
}

function erp_mutabakat_hazir(PDO $pdo): bool
{
    return erp_mutabakat_eksik_tablolar($pdo) === [];
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function erp_mutabakat_migrate(PDO $pdo): array
{
    $sqlite = db_is_sqlite($pdo);
    $ai = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'cari_kredi_limitleri' => "CREATE TABLE IF NOT EXISTS `cari_kredi_limitleri` (
            `cari_id` INT PRIMARY KEY,
            `risk_limiti` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `uyari_yuzde` TINYINT NOT NULL DEFAULT 80,
            `blokaj_aktif` TINYINT NOT NULL DEFAULT 0,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `notlar` VARCHAR(400) NOT NULL DEFAULT '',
            `guncelleyen_id` INT NULL,
            `guncelleme_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'kur_farki_degerlemeler' => "CREATE TABLE IF NOT EXISTS `kur_farki_degerlemeler` (
            `id` $ai,
            `donem_kodu` VARCHAR(20) NOT NULL UNIQUE,
            `degerleme_tarih` DATE NOT NULL,
            `toplam_kar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `toplam_zarar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `net_fark` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `muh_fis_id` INT NULL,
            `detay_json` TEXT NULL,
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

// ── 1) Cari Kredi / Risk Limiti ────────────────────────────────────────────

/** @return array{ok:bool,hata?:string,mesaj?:string,kod?:int} */
function cari_risk_limit_kaydet(PDO $pdo, array $g, int $uid): array
{
    $cariId = (int)($g['cari_id'] ?? 0);
    $limit  = max(0.0, muh_tutar_coz($g['risk_limiti'] ?? 0));
    $uyari  = max(10, min(100, (int)($g['uyari_yuzde'] ?? 80)));
    $blokaj = !empty($g['blokaj_aktif']) ? 1 : 0;
    $pb     = strtoupper(trim((string)($g['para_birimi'] ?? 'TRY')));
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';
    $notlar = mb_substr(trim((string)($g['notlar'] ?? '')), 0, 400, 'UTF-8');

    if ($cariId <= 0) return ['ok' => false, 'hata' => 'Geçerli bir cari kartı seçin.', 'kod' => 422];
    if (db_table_exists($pdo, 'cari_kartlar')) {
        $st = $pdo->prepare('SELECT id FROM `cari_kartlar` WHERE id = ?');
        $st->execute([$cariId]);
        if (!$st->fetchColumn()) return ['ok' => false, 'hata' => 'Cari kartı bulunamadı.', 'kod' => 404];
    }

    return db_tx($pdo, static function (PDO $p) use ($cariId, $limit, $uyari, $blokaj, $pb, $notlar, $uid): array {
        $stE = $p->prepare('SELECT * FROM `cari_kredi_limitleri` WHERE cari_id = ?' . db_for_update($p));
        $stE->execute([$cariId]);
        $eski = $stE->fetch(PDO::FETCH_ASSOC);
        if ($eski) {
            $p->prepare('UPDATE `cari_kredi_limitleri` SET risk_limiti=?, uyari_yuzde=?, blokaj_aktif=?, para_birimi=?, notlar=?, guncelleyen_id=?, guncelleme_at=? WHERE cari_id=?')
                ->execute([$limit, $uyari, $blokaj, $pb, $notlar, $uid ?: null, date('Y-m-d H:i:s'), $cariId]);
        } else {
            $p->prepare('INSERT INTO `cari_kredi_limitleri` (cari_id, risk_limiti, uyari_yuzde, blokaj_aktif, para_birimi, notlar, guncelleyen_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$cariId, $limit, $uyari, $blokaj, $pb, $notlar, $uid ?: null]);
        }
        if (function_exists('audit_log_event')) {
            audit_log_event('update', 'cari_kredi_limit', $cariId, $eski ?: null, ['risk_limiti' => $limit, 'blokaj_aktif' => $blokaj]);
        }
        return ['ok' => true, 'mesaj' => 'Cari kredi/risk limiti kaydedildi.'];
    });
}

/**
 * Verilen cari için mevcut borç bakiyesi + ek tutarı kredi limitiyle karşılaştırır.
 * @return array{tanimli:bool,durum:string,mevcut_bakiye:float,yeni_bakiye:float,limit:float,kalan_limit:float,oran_yuzde:float,blokaj:bool,mesaj:string}
 */
function cari_risk_kontrol(PDO $pdo, int $cariId, float $ekTutar = 0.0): array
{
    $bos = [
        'tanimli' => false, 'durum' => 'ok', 'mevcut_bakiye' => 0.0, 'yeni_bakiye' => round($ekTutar, 2),
        'limit' => 0.0, 'kalan_limit' => 0.0, 'oran_yuzde' => 0.0, 'blokaj' => false, 'mesaj' => 'Risk limiti tanımlı değil.',
    ];
    if ($cariId <= 0 || !db_table_exists($pdo, 'cari_kredi_limitleri')) return $bos;

    $st = $pdo->prepare('SELECT * FROM `cari_kredi_limitleri` WHERE cari_id = ?');
    $st->execute([$cariId]);
    $lim = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lim || (float)$lim['risk_limiti'] <= 0.0) return $bos;

    $bakiye = 0.0;
    if (db_table_exists($pdo, 'cari_hareketler')) {
        try {
            $stB = $pdo->prepare(
                "SELECT COALESCE(SUM(CASE WHEN yon = 'B' THEN tutar * COALESCE(NULLIF(kur, 0), 1)
                                          ELSE -tutar * COALESCE(NULLIF(kur, 0), 1) END), 0)
                 FROM `cari_hareketler`
                 WHERE cari_id = ? AND durum = 'gecerli'"
            );
            $stB->execute([$cariId]);
            $bakiye = round((float)$stB->fetchColumn(), 2);
        } catch (PDOException $e) {
            $bakiye = 0.0;
        }
    }

    $limit = round((float)$lim['risk_limiti'], 2);
    $yeni  = round($bakiye + $ekTutar, 2);
    $kalan = round($limit - $yeni, 2);
    $oran  = $limit > 0 ? round(($yeni / $limit) * 100.0, 1) : 0.0;
    $uyariEsik = (int)$lim['uyari_yuzde'];
    $blokajAktif = !empty($lim['blokaj_aktif']);

    if ($yeni > $limit) {
        $durum = $blokajAktif ? 'blokaj' : 'uyari';
        $mesaj = sprintf('Kredi limiti aşıldı! Yeni bakiye: %.2f / Limit: %.2f (%s)', $yeni, $limit, $blokajAktif ? 'BLOKAJ' : 'UYARI');
    } elseif ($oran >= $uyariEsik) {
        $durum = 'uyari';
        $mesaj = sprintf('Kredi limiti %%%.1f dolulukta (Kalan: %.2f).', $oran, max(0.0, $kalan));
    } else {
        $durum = 'ok';
        $mesaj = sprintf('Limit uygun (Kalan: %.2f).', $kalan);
    }

    return [
        'tanimli'       => true,
        'durum'         => $durum,
        'mevcut_bakiye' => $bakiye,
        'yeni_bakiye'   => $yeni,
        'limit'         => $limit,
        'kalan_limit'   => $kalan,
        'oran_yuzde'    => $oran,
        'blokaj'        => ($durum === 'blokaj'),
        'mesaj'         => $mesaj,
    ];
}

/** @return list<array<string,mixed>> */
function cari_risk_limit_listesi(PDO $pdo): array
{
    if (!db_table_exists($pdo, 'cari_kredi_limitleri')) return [];
    $cariVar = db_table_exists($pdo, 'cari_kartlar');
    $sql = $cariVar
        ? 'SELECT l.*, c.unvan, c.kod AS cari_kod FROM `cari_kredi_limitleri` l LEFT JOIN `cari_kartlar` c ON c.id = l.cari_id ORDER BY l.risk_limiti DESC'
        : 'SELECT l.*, "" AS unvan, "" AS cari_kod FROM `cari_kredi_limitleri` l ORDER BY l.risk_limiti DESC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $k = cari_risk_kontrol($pdo, (int)$r['cari_id'], 0.0);
        $r['mevcut_bakiye'] = $k['mevcut_bakiye'];
        $r['oran_yuzde']    = $k['oran_yuzde'];
        $r['risk_durum']    = $k['durum'];
    }
    unset($r);
    return $rows;
}

// ── 2) Form BA / BS Aylık Mutabakat Listesi ────────────────────────────────

/**
 * Geçerli fatura_belgeler kayıtlarından aylık Form BA (Alış) ve Form BS (Satış) listesi üretir.
 * @return array{yil:int,ay:int,esik:float,ba:list<array<string,mixed>>,bs:list<array<string,mixed>>,ba_toplam:float,bs_toplam:float}
 */
function cari_babs_hesapla(PDO $pdo, int $yil, int $ay, float $esik = 5000.0): array
{
    $out = ['yil' => $yil, 'ay' => $ay, 'esik' => $esik, 'ba' => [], 'bs' => [], 'ba_toplam' => 0.0, 'bs_toplam' => 0.0];
    if ($ay < 1 || $ay > 12 || !db_table_exists($pdo, 'fatura_belgeler')) return $out;

    $bas = sprintf('%04d-%02d-01', $yil, $ay);
    $bit = date('Y-m-t', strtotime($bas));
    $cariVar = db_table_exists($pdo, 'cari_kartlar');
    $join = $cariVar ? 'LEFT JOIN `cari_kartlar` c ON c.id = f.cari_id' : '';
    $cSel = $cariVar
        ? "COALESCE(NULLIF(f.cari_unvan, ''), c.unvan, '') AS unvan, COALESCE(NULLIF(f.cari_vergi_no, ''), c.vergi_no, '') AS vergi_no"
        : "COALESCE(f.cari_unvan, '') AS unvan, COALESCE(f.cari_vergi_no, '') AS vergi_no";

    $st = $pdo->prepare(
        "SELECT f.tur, f.cari_id, $cSel, f.net_toplam, f.kur
         FROM `fatura_belgeler` f
         $join
         WHERE f.durum = 'gecerli' AND f.tur IN ('alis', 'alis_mustahsil', 'satis', 'satis_ihracat')
           AND f.tarih >= ? AND f.tarih <= ?"
    );
    try {
        $st->execute([$bas, $bit]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $out;
    }

    $gruplar = [];
    foreach ($rows as $r) {
        $tur = (string)$r['tur'];
        $form = ($tur === 'alis' || $tur === 'alis_mustahsil') ? 'ba' : 'bs';
        $cid = (int)$r['cari_id'];
        $kur = (float)($r['kur'] ?? 0);
        if ($kur <= 0.0) $kur = 1.0;
        $tl = round((float)$r['net_toplam'] * $kur, 2);
        $key = $form . ':' . $cid;
        if (!isset($gruplar[$key])) {
            $unv = trim((string)$r['unvan']);
            $gruplar[$key] = [
                'form'         => $form,
                'cari_id'      => $cid,
                'unvan'        => $unv !== '' ? $unv : ('Cari #' . $cid),
                'vergi_no'     => (string)($r['vergi_no'] ?? ''),
                'belge_sayisi' => 0,
                'tutar_tl'     => 0.0,
            ];
        }
        $gruplar[$key]['belge_sayisi']++;
        $gruplar[$key]['tutar_tl'] = round($gruplar[$key]['tutar_tl'] + $tl, 2);
    }

    foreach ($gruplar as $g) {
        if ($g['tutar_tl'] < $esik) continue;
        $form = $g['form'];
        unset($g['form']);
        $out[$form][] = $g;
        $out[$form . '_toplam'] = round($out[$form . '_toplam'] + $g['tutar_tl'], 2);
    }
    usort($out['ba'], static fn(array $a, array $b): int => $b['tutar_tl'] <=> $a['tutar_tl']);
    usort($out['bs'], static fn(array $a, array $b): int => $b['tutar_tl'] <=> $a['tutar_tl']);
    return $out;
}

// ── 3) Dönem Sonu Döviz Kur Farkı Değerleme Motoru (646 / 656) ──────────────

/**
 * Verilen tarihteki döviz kurunu doviz_kurlari tablosundan okur (yoksa verilen varsayılan haritadan).
 * @param array<string,float> $manuelKurlar
 */
function erp_kur_oku(PDO $pdo, string $pb, string $tarih, array $manuelKurlar = []): ?float
{
    $pb = strtoupper(trim($pb));
    if ($pb === 'TRY' || $pb === '') return 1.0;
    if (isset($manuelKurlar[$pb]) && $manuelKurlar[$pb] > 0) return (float)$manuelKurlar[$pb];
    if (!db_table_exists($pdo, 'doviz_kurlari')) return null;
    try {
        $st = $pdo->prepare(
            'SELECT doviz_alis, doviz_satis FROM `doviz_kurlari` WHERE kod = ? AND tarih <= ? ORDER BY tarih DESC LIMIT 1'
        );
        $st->execute([$pb, $tarih]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $v = (float)($r['doviz_alis'] ?: $r['doviz_satis']);
        return $v > 0 ? $v : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Açık dövizli pozisyonların (muh_fis_satirlar üzerinde para_birimi <> 'TRY') değerleme tarihindeki
 * kur farkı kâr (646) / zarar (656) analizini hesaplar.
 * @param array<string,float> $manuelKurlar
 * @return array{tarih:string,satirlar:list<array<string,mixed>>,toplam_kar:float,toplam_zarar:float,net_fark:float,eksik_kurlar:list<string>}
 */
function erp_kur_farki_hesapla(PDO $pdo, string $tarih, array $manuelKurlar = []): array
{
    $satirlar = []; $topKar = 0.0; $topZarar = 0.0; $eksik = [];
    if (!db_table_exists($pdo, 'muh_fis_satirlar') || !db_table_exists($pdo, 'muh_fisler')) {
        return ['tarih' => $tarih, 'satirlar' => [], 'toplam_kar' => 0.0, 'toplam_zarar' => 0.0, 'net_fark' => 0.0, 'eksik_kurlar' => []];
    }

    $st = $pdo->prepare(
        "SELECT s.hesap_kod, h.ad AS hesap_ad, s.para_birimi,
                ROUND(SUM(CASE WHEN s.borc > 0 THEN s.doviz_tutar ELSE -s.doviz_tutar END), 2) AS net_doviz,
                ROUND(SUM(s.borc - s.alacak), 2) AS defter_tl
         FROM `muh_fis_satirlar` s
         INNER JOIN `muh_fisler` f ON f.id = s.fis_id
         LEFT JOIN `muh_hesap_plani` h ON h.id = s.hesap_id
         WHERE f.tarih <= ? AND s.para_birimi <> 'TRY' AND s.doviz_tutar <> 0
           AND SUBSTR(s.hesap_kod, 1, 1) IN ('1', '3')
         GROUP BY s.hesap_kod, h.ad, s.para_birimi
         ORDER BY s.hesap_kod ASC, s.para_birimi ASC"
    );
    $st->execute([$tarih]);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $netDoviz = round((float)$r['net_doviz'], 2);
        $defterTl = round((float)$r['defter_tl'], 2);
        if (abs($netDoviz) < 0.01) continue;
        $pb = strtoupper((string)$r['para_birimi']);
        $kur = erp_kur_oku($pdo, $pb, $tarih, $manuelKurlar);
        if ($kur === null) {
            $eksik[$pb] = true;
            continue;
        }
        $degerlenmisTl = round($netDoviz * $kur, 2);
        // Aktif/Alacak pozisyonunda değerleme > defter → kâr; Pasif/Borç (negatif net_doviz) değerleme > defter → kâr (borç azalması)
        $fark = round($degerlenmisTl - $defterTl, 2);
        if (abs($fark) < 0.01) continue;
        if ($fark > 0) $topKar = round($topKar + $fark, 2);
        else $topZarar = round($topZarar + abs($fark), 2);

        $satirlar[] = [
            'hesap_kod'      => (string)$r['hesap_kod'],
            'hesap_ad'       => (string)($r['hesap_ad'] ?? ''),
            'para_birimi'    => $pb,
            'net_doviz'      => $netDoviz,
            'defter_tl'      => $defterTl,
            'kur'            => $kur,
            'degerlenmis_tl' => $degerlenmisTl,
            'fark_tl'        => $fark, // >0 → 646 Kambiyo Kârı, <0 → 656 Kambiyo Zararı
        ];
    }

    return [
        'tarih'        => $tarih,
        'satirlar'     => $satirlar,
        'toplam_kar'   => $topKar,
        'toplam_zarar' => $topZarar,
        'net_fark'     => round($topKar - $topZarar, 2),
        'eksik_kurlar' => array_keys($eksik),
    ];
}

/**
 * Kur farkı değerlemesini uygular ve Genel Muhasebe (muh_fisler) içine 646/656 yevmiye fişini keser (idempotent).
 * @param array<string,float> $manuelKurlar
 * @return array{ok:bool,id?:int,fis_id?:int,fis_no?:string,net_fark?:float,hata?:string,kod?:int}
 */
function erp_kur_farki_uygula(PDO $pdo, string $donemKodu, string $tarih, int $uid, array $manuelKurlar = []): array
{
    $donemKodu = trim($donemKodu);
    if (!preg_match('/^\d{4}-\d{2}(?:-[A-Z0-9]+)?$/', $donemKodu)) {
        return ['ok' => false, 'hata' => 'Dönem kodu YYYY-AA biçiminde olmalıdır (örn. 2026-11).', 'kod' => 422];
    }
    if (!muh_tarih_gecerli($tarih)) return ['ok' => false, 'hata' => 'Geçersiz değerleme tarihi.', 'kod' => 422];
    if (!muh_hazir($pdo)) return ['ok' => false, 'hata' => 'Genel Muhasebe tabloları kurulu değil.', 'kod' => 503];

    return db_tx($pdo, static function (PDO $p) use ($donemKodu, $tarih, $uid, $manuelKurlar): array {
        $stE = $p->prepare('SELECT id, muh_fis_id FROM `kur_farki_degerlemeler` WHERE donem_kodu = ?' . db_for_update($p));
        $stE->execute([$donemKodu]);
        if ($stE->fetch(PDO::FETCH_ASSOC)) {
            return ['ok' => false, 'hata' => $donemKodu . ' dönemi için kur farkı değerlemesi zaten çalıştırılmış.', 'kod' => 409];
        }

        $hesap = erp_kur_farki_hesapla($p, $tarih, $manuelKurlar);
        if ($hesap['eksik_kurlar'] !== []) {
            return ['ok' => false, 'hata' => 'Eksik döviz kuru: ' . implode(', ', $hesap['eksik_kurlar']) . ' (Günlük Kurlar ekranından güncelleyin).', 'kod' => 422];
        }
        if ($hesap['satirlar'] === []) {
            return ['ok' => false, 'hata' => 'Değerlenecek kur farkı bulunan açık döviz pozisyonu yok.', 'kod' => 422];
        }

        $fisSatirlar = [];
        foreach ($hesap['satirlar'] as $s) {
            $fark = (float)$s['fark_tl'];
            if ($fark > 0) {
                $fisSatirlar[] = ['hesap_kod' => $s['hesap_kod'], 'borc' => $fark, 'alacak' => 0.0, 'aciklama' => $donemKodu . ' Kur Değerleme (' . $s['para_birimi'] . ' @ ' . $s['kur'] . ')'];
                $fisSatirlar[] = ['hesap_kod' => '646', 'borc' => 0.0, 'alacak' => $fark, 'aciklama' => $donemKodu . ' Kambiyo Kârı — ' . $s['hesap_kod']];
            } else {
                $zarar = abs($fark);
                $fisSatirlar[] = ['hesap_kod' => '656', 'borc' => $zarar, 'alacak' => 0.0, 'aciklama' => $donemKodu . ' Kambiyo Zararı — ' . $s['hesap_kod']];
                $fisSatirlar[] = ['hesap_kod' => $s['hesap_kod'], 'borc' => 0.0, 'alacak' => $zarar, 'aciklama' => $donemKodu . ' Kur Değerleme (' . $s['para_birimi'] . ' @ ' . $s['kur'] . ')'];
            }
        }

        $fis = muh_fis_kaydet($p, [
            'tarih'    => $tarih,
            'tur'      => 'kur_farki',
            'aciklama' => $donemKodu . ' Dönem Sonu Döviz Kur Farkı Değerleme Fişi',
            'satirlar' => $fisSatirlar,
        ], $uid);
        if (empty($fis['ok'])) return $fis;

        $p->prepare(
            'INSERT INTO `kur_farki_degerlemeler` (donem_kodu, degerleme_tarih, toplam_kar, toplam_zarar, net_fark, muh_fis_id, detay_json, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $donemKodu, $tarih, $hesap['toplam_kar'], $hesap['toplam_zarar'], $hesap['net_fark'],
            (int)$fis['id'], json_encode($hesap['satirlar'], JSON_UNESCAPED_UNICODE), $uid ?: null,
        ]);
        $id = (int)$p->lastInsertId();

        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'kur_farki_degerleme', $id, null, [
                'donem' => $donemKodu, 'fis_no' => $fis['fis_no'], 'net_fark' => $hesap['net_fark'],
            ]);
        }
        return [
            'ok'       => true,
            'id'       => $id,
            'fis_id'   => (int)$fis['id'],
            'fis_no'   => (string)$fis['fis_no'],
            'net_fark' => $hesap['net_fark'],
            'mesaj'    => $donemKodu . ' kur farkı değerlemesi işlendi (' . $fis['fis_no'] . ').',
        ];
    });
}

function erp_mutabakat_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = erp_mutabakat_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Risk/Kur Farkı tabloları kurulmamış (' . implode(', ', $eksik) . '). migrate.php#erp-mutabakat kartını çalıştırın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header') && function_exists('muh_sema_uyari')) {
        render_header('Risk, BA/BS & Kur Farkı');
        echo muh_sema_uyari($eksik, 'erp-mutabakat', 'Cari Risk Limiti & Kur Farkı Değerleme');
        if (function_exists('render_footer')) render_footer();
        exit;
    }
    http_response_code(503);
    exit('Risk / Kur Farkı şeması kurulmamış.');
}

/** @return list<array<string,mixed>> */
function erp_kur_farki_listesi(PDO $pdo): array
{
    if (!db_table_exists($pdo, 'kur_farki_degerlemeler')) return [];
    return $pdo->query(
        'SELECT id, donem_kodu, degerleme_tarih, toplam_kar, toplam_zarar, net_fark, muh_fis_id, olusturma_at
         FROM `kur_farki_degerlemeler` ORDER BY degerleme_tarih DESC, id DESC LIMIT 100'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array{id:int,kod:string,unvan:string}> */
function erp_cari_secenekleri(PDO $pdo): array
{
    if (!db_table_exists($pdo, 'cari_kartlar')) return [];
    try {
        return $pdo->query('SELECT id, kod, unvan FROM `cari_kartlar` WHERE aktif = 1 ORDER BY unvan ASC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

