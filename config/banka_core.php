<?php
// =========================================================
// config/banka_core.php — ERP-6 Banka Entegrasyonu, MT940/CSV Mutabakat, Kredi Taksit & POS Takibi
//
// Kapsam:
//   - banka_hesaplari        : IBAN, şube, para birimi, kredi limiti, TDHP 102 alt hesabı
//   - banka_ekstreler        : SWIFT MT940 (:61:/:86:) veya CSV ekstre içe aktarma + otomatik/manuel mutabakat
//   - banka_krediler + banka_kredi_taksitler : Eşit taksitli (annuite) ödeme planı (anapara/faiz/BSMV) + TDHP 300/780/102 fişi
//   - banka_pos_hareketler   : POS işlem, komisyon %, net tutar, valör/bloke çözümü takibi
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/onay_core.php';

/** @return list<string> */
function banka_tablolar(): array
{
    return ['banka_hesaplari', 'banka_ekstreler', 'banka_krediler', 'banka_kredi_taksitler', 'banka_pos_hareketler'];
}

/** @return list<string> */
function banka_eksik_tablolar(PDO $pdo): array
{
    $e = [];
    foreach (banka_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $e[] = $t;
    }
    return $e;
}

function banka_hazir(PDO $pdo): bool
{
    return banka_eksik_tablolar($pdo) === [];
}

function banka_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = banka_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Banka tabloları kurulmamış (' . implode(', ', $eksik) . '). migrate.php#banka-mutabakat kartını çalıştırın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header') && function_exists('erp_yon_sema_uyari')) {
        render_header('Banka Mutabakat, Kredi & POS');
        echo erp_yon_sema_uyari($eksik, 'banka-mutabakat', 'Banka Mutabakat, Kredi & POS (ERP-6)');
        if (function_exists('render_footer')) render_footer();
        exit;
    }
    http_response_code(503);
    exit('Banka şeması kurulmamış.');
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function banka_migrate(PDO $pdo): array
{
    $sqlite = db_is_sqlite($pdo);
    $ai  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'banka_hesaplari' => "CREATE TABLE IF NOT EXISTS `banka_hesaplari` (
            `id` $ai,
            `kod` VARCHAR(40) NOT NULL UNIQUE,
            `banka_ad` VARCHAR(140) NOT NULL,
            `sube_ad` VARCHAR(120) NOT NULL DEFAULT '',
            `iban` VARCHAR(40) NOT NULL DEFAULT '',
            `hesap_no` VARCHAR(40) NOT NULL DEFAULT '',
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `kredi_limiti` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `acilis_bakiye` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `tdhp_hesap_kod` VARCHAR(30) NOT NULL DEFAULT '102',
            `aktif` TINYINT NOT NULL DEFAULT 1,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'banka_ekstreler' => "CREATE TABLE IF NOT EXISTS `banka_ekstreler` (
            `id` $ai,
            `hesap_id` INT NOT NULL,
            `tarih` DATE NOT NULL,
            `valor_tarih` DATE NULL,
            `referans_no` VARCHAR(80) NOT NULL DEFAULT '',
            `aciklama` VARCHAR(400) NOT NULL DEFAULT '',
            `tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `eslesme_durum` VARCHAR(20) NOT NULL DEFAULT 'eslesmedi',
            `eslesen_modul` VARCHAR(40) NULL,
            `eslesen_id` INT NULL,
            `muh_fis_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'banka_krediler' => "CREATE TABLE IF NOT EXISTS `banka_krediler` (
            `id` $ai,
            `sozlesme_no` VARCHAR(60) NOT NULL UNIQUE,
            `hesap_id` INT NOT NULL,
            `banka_ad` VARCHAR(140) NOT NULL DEFAULT '',
            `anapara` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `aylik_faiz_yuzde` DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
            `taksit_sayisi` INT NOT NULL DEFAULT 12,
            `bas_tarih` DATE NOT NULL,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `durum` VARCHAR(20) NOT NULL DEFAULT 'aktif',
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'banka_kredi_taksitler' => "CREATE TABLE IF NOT EXISTS `banka_kredi_taksitler` (
            `id` $ai,
            `kredi_id` INT NOT NULL,
            `taksit_no` INT NOT NULL,
            `vade_tarih` DATE NOT NULL,
            `anapara_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `faiz_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `vergi_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `toplam_taksit` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `kalan_anapara` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `durum` VARCHAR(20) NOT NULL DEFAULT 'bekliyor',
            `odeme_tarih` DATE NULL,
            `muh_fis_id` INT NULL
        )$eng",
        'banka_pos_hareketler' => "CREATE TABLE IF NOT EXISTS `banka_pos_hareketler` (
            `id` $ai,
            `hesap_id` INT NOT NULL,
            `islem_tarih` DATE NOT NULL,
            `valor_tarih` DATE NOT NULL,
            `kart_tipi` VARCHAR(40) NOT NULL DEFAULT 'Visa/MC',
            `brut_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `komisyon_yuzde` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
            `komisyon_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `net_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `durum` VARCHAR(20) NOT NULL DEFAULT 'blokede',
            `cozuldu_at` DATETIME NULL,
            `aciklama` VARCHAR(300) NOT NULL DEFAULT '',
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

// ── 1) Banka Hesapları ─────────────────────────────────────────────────────

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function banka_hesap_kaydet(PDO $pdo, array $g, int $uid): array
{
    $kod   = mb_strtoupper(mb_substr(trim((string)($g['kod'] ?? '')), 0, 40, 'UTF-8'), 'UTF-8');
    $banka = mb_substr(trim((string)($g['banka_ad'] ?? '')), 0, 140, 'UTF-8');
    $sube  = mb_substr(trim((string)($g['sube_ad'] ?? '')), 0, 120, 'UTF-8');
    $iban  = strtoupper(preg_replace('/\s+/', '', (string)($g['iban'] ?? '')) ?? '');
    $hno   = mb_substr(trim((string)($g['hesap_no'] ?? '')), 0, 40, 'UTF-8');
    $pb    = strtoupper(trim((string)($g['para_birimi'] ?? 'TRY')));
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';
    $limit = max(0.0, round(erp_yon_sayi($g['kredi_limiti'] ?? 0), 2));
    $acilis = round(erp_yon_sayi($g['acilis_bakiye'] ?? 0), 2);
    $tdhp  = trim((string)($g['tdhp_hesap_kod'] ?? '102')) ?: '102';

    if ($banka === '') return ['ok' => false, 'hata' => 'Banka adı zorunludur.', 'kod' => 422];
    if ($kod === '') $kod = 'BNK-' . substr(md5($banka . $iban . microtime()), 0, 6);
    if ($iban !== '' && !preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban)) {
        return ['ok' => false, 'hata' => 'IBAN biçimi geçersiz (örn. TR330006100519786457841326).', 'kod' => 422];
    }

    return db_tx($pdo, static function (PDO $p) use ($kod, $banka, $sube, $iban, $hno, $pb, $limit, $acilis, $tdhp, $uid): array {
        $chk = $p->prepare('SELECT id FROM `banka_hesaplari` WHERE kod = ?');
        $chk->execute([$kod]);
        if ($chk->fetchColumn()) return ['ok' => false, 'hata' => "Bu banka hesap kodu zaten mevcut ($kod).", 'kod' => 409];

        $p->prepare(
            'INSERT INTO `banka_hesaplari` (kod, banka_ad, sube_ad, iban, hesap_no, para_birimi, kredi_limiti, acilis_bakiye, tdhp_hesap_kod, aktif) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([$kod, $banka, $sube, $iban, $hno, $pb, $limit, $acilis, $tdhp]);
        $id = (int)$p->lastInsertId();
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'banka_hesap', $id, null, ['kod' => $kod, 'banka' => $banka, 'pb' => $pb, 'uid' => $uid]);
        }
        return ['ok' => true, 'id' => $id, 'mesaj' => "Banka hesabı kaydedildi ($kod — $banka)."];
    });
}

// ── 2) MT940 / CSV Ekstre İçe Aktarma & Otomatik Mutabakat ─────────────────

/**
 * SWIFT MT940 (:61:/:86:) veya noktalı virgül CSV (`YYYY-MM-DD;REF;ACIKLAMA;TUTAR`) ekstre metnini ayrıştırıp kaydeder.
 * @return array{ok:bool,eklenen:int,atlanan:int,hata?:string,mesaj?:string,kod?:int}
 */
function banka_ekstre_ice_aktar(PDO $pdo, int $hesapId, string $hamMetin, int $uid): array
{
    if ($hesapId <= 0) return ['ok' => false, 'eklenen' => 0, 'atlanan' => 0, 'hata' => 'Geçerli bir banka hesabı seçin.', 'kod' => 422];
    $hamMetin = trim($hamMetin);
    if ($hamMetin === '') return ['ok' => false, 'eklenen' => 0, 'atlanan' => 0, 'hata' => 'İçe aktarılacak ekstre metni boş.', 'kod' => 422];

    $kayitlar = [];
    if (str_contains($hamMetin, ':61:')) {
        // SWIFT MT940 ayrıştırıcı: :61:YYMMDD[MMDD](C|D)amount... + sonraki satır :86:aciklama
        $satirlar = preg_split('/\r\n|\r|\n/', $hamMetin) ?: [];
        $sonIdx = -1;
        foreach ($satirlar as $line) {
            $line = trim($line);
            if (preg_match('/^:61:(\d{6})(?:\d{4})?(RC|RD|C|D)([0-9,]+)(?:[A-Z0-9]{4})?(.*)$/', $line, $m)) {
                $yy = '20' . substr($m[1], 0, 2);
                $mm = substr($m[1], 2, 2);
                $dd = substr($m[1], 4, 2);
                $tarih = "$yy-$mm-$dd";
                $tutar = (float)str_replace(',', '.', $m[3]);
                if ($m[2] === 'D' || $m[2] === 'RC') $tutar = -$tutar;
                $ref = mb_substr(trim((string)$m[4]), 0, 80, 'UTF-8');
                $kayitlar[] = ['tarih' => $tarih, 'ref' => $ref, 'aciklama' => '', 'tutar' => round($tutar, 2)];
                $sonIdx = count($kayitlar) - 1;
            } elseif (str_starts_with($line, ':86:') && $sonIdx >= 0) {
                $kayitlar[$sonIdx]['aciklama'] = mb_substr(trim(substr($line, 4)), 0, 400, 'UTF-8');
            }
        }
    } else {
        // CSV: YYYY-MM-DD;REF;ACIKLAMA;TUTAR
        foreach (preg_split('/\r\n|\r|\n/', $hamMetin) ?: [] as $line) {
            $cols = str_getcsv(trim($line), ';', '"', '\\');
            if (count($cols) < 4) continue;
            $t = trim((string)$cols[0]);
            if (!erp_yon_tarih_gecerli($t)) continue;
            $tutar = round(erp_yon_sayi($cols[3]), 2);
            if (str_starts_with(trim((string)$cols[3]), '-')) $tutar = -abs($tutar);
            $kayitlar[] = [
                'tarih'    => $t,
                'ref'      => mb_substr(trim((string)$cols[1]), 0, 80, 'UTF-8'),
                'aciklama' => mb_substr(trim((string)$cols[2]), 0, 400, 'UTF-8'),
                'tutar'    => $tutar,
            ];
        }
    }

    if ($kayitlar === []) return ['ok' => false, 'eklenen' => 0, 'atlanan' => 0, 'hata' => 'Geçerli MT940 (:61:) veya CSV (YYYY-AA-GG;REF;AÇIKLAMA;TUTAR) satırı bulunamadı.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($hesapId, $kayitlar, $uid): array {
        $eklenen = 0; $atlanan = 0;
        foreach ($kayitlar as $k) {
            if ($k['ref'] !== '') {
                $chk = $p->prepare('SELECT 1 FROM `banka_ekstreler` WHERE hesap_id = ? AND tarih = ? AND referans_no = ? AND tutar = ? LIMIT 1');
                $chk->execute([$hesapId, $k['tarih'], $k['ref'], $k['tutar']]);
                if ($chk->fetchColumn()) { $atlanan++; continue; }
            }
            $p->prepare(
                "INSERT INTO `banka_ekstreler` (hesap_id, tarih, valor_tarih, referans_no, aciklama, tutar, eslesme_durum) VALUES (?, ?, ?, ?, ?, ?, 'eslesmedi')"
            )->execute([$hesapId, $k['tarih'], $k['tarih'], $k['ref'], $k['aciklama'], $k['tutar']]);
            $eklenen++;
        }
        if (function_exists('audit_log_event')) {
            audit_log_event('import', 'banka_ekstre', $hesapId, null, ['eklenen' => $eklenen, 'atlanan' => $atlanan, 'uid' => $uid]);
        }
        return ['ok' => true, 'eklenen' => $eklenen, 'atlanan' => $atlanan, 'mesaj' => "Ekstre aktarıldı: $eklenen yeni hareket, $atlanan mükerrer atlandı."];
    });
}

/**
 * Eşleşmemiş ekstre hareketlerini fatura_belgeler tutarına göre (tam eşleşme) otomatik eşleştirir.
 * @return array{ok:bool,eslesen:int,mesaj:string}
 */
function banka_ekstre_otomatik_eslestir(PDO $pdo, int $hesapId, int $uid): array
{
    $eslesen = 0;
    if (!db_table_exists($pdo, 'fatura_belgeler')) {
        return ['ok' => true, 'eslesen' => 0, 'mesaj' => 'Fatura tablosu yok; yalnız manuel eşleştirme kullanılabilir.'];
    }
    $st = $pdo->prepare("SELECT * FROM `banka_ekstreler` WHERE hesap_id = ? AND eslesme_durum = 'eslesmedi' ORDER BY tarih ASC LIMIT 200");
    $st->execute([$hesapId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ek) {
        $absTut = round(abs((float)$ek['tutar']), 2);
        if ($absTut <= 0.0) continue;
        $turListe = (float)$ek['tutar'] > 0 ? "('satis','satis_ihracat')" : "('alis','alis_mustahsil')";
        $stF = $pdo->prepare("SELECT id, belge_no FROM `fatura_belgeler` WHERE durum = 'gecerli' AND tur IN $turListe AND ABS(genel_toplam - ?) < 0.02 ORDER BY tarih DESC LIMIT 1");
        $stF->execute([$absTut]);
        if ($ft = $stF->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("UPDATE `banka_ekstreler` SET eslesme_durum='eslesti', eslesen_modul='fatura', eslesen_id=? WHERE id=?")
                ->execute([(int)$ft['id'], (int)$ek['id']]);
            $eslesen++;
        }
    }
    if ($eslesen > 0 && function_exists('audit_log_event')) {
        audit_log_event('reconcile', 'banka_ekstre', $hesapId, null, ['eslesen' => $eslesen, 'uid' => $uid]);
    }
    return ['ok' => true, 'eslesen' => $eslesen, 'mesaj' => "$eslesen ekstre hareketi faturalarla otomatik eşleştirildi."];
}

// ── 3) Kredi Sözleşmesi & Eşit Taksit Ödeme Planı ──────────────────────────

/** @return array{ok:bool,id?:int,taksit_tutari?:float,hata?:string,mesaj?:string,kod?:int} */
function banka_kredi_olustur(PDO $pdo, array $g, int $uid): array
{
    $sozNo  = mb_strtoupper(mb_substr(trim((string)($g['sozlesme_no'] ?? '')), 0, 60, 'UTF-8'), 'UTF-8');
    $hesapId = (int)($g['hesap_id'] ?? 0);
    $anapara = round(erp_yon_sayi($g['anapara'] ?? 0), 2);
    $faiz   = max(0.0, round(erp_yon_sayi($g['aylik_faiz_yuzde'] ?? 0), 4));
    $taksit = max(1, min(120, (int)($g['taksit_sayisi'] ?? 12)));
    $basTar = trim((string)($g['bas_tarih'] ?? date('Y-m-d')));
    $vergiOran = max(0.0, min(0.50, round(erp_yon_sayi($g['vergi_oran'] ?? 0.20), 4))); // BSMV+KKDF (~%20 ticari kredi)

    if ($hesapId <= 0) return ['ok' => false, 'hata' => 'Banka hesabı seçin.', 'kod' => 422];
    if ($anapara <= 0.0) return ['ok' => false, 'hata' => 'Kredi anaparası sıfırdan büyük olmalıdır.', 'kod' => 422];
    if (!erp_yon_tarih_gecerli($basTar)) return ['ok' => false, 'hata' => 'Geçersiz başlangıç tarihi.', 'kod' => 422];
    if ($sozNo === '') $sozNo = 'KRD-' . date('Ymd', strtotime($basTar)) . '-' . random_int(100, 999);

    return db_tx($pdo, static function (PDO $p) use ($sozNo, $hesapId, $anapara, $faiz, $taksit, $basTar, $vergiOran, $uid): array {
        $stH = $p->prepare('SELECT banka_ad, para_birimi FROM `banka_hesaplari` WHERE id = ?');
        $stH->execute([$hesapId]);
        $hs = $stH->fetch(PDO::FETCH_ASSOC);
        if (!$hs) return ['ok' => false, 'hata' => 'Banka hesabı bulunamadı.', 'kod' => 404];

        $p->prepare(
            "INSERT INTO `banka_krediler` (sozlesme_no, hesap_id, banka_ad, anapara, aylik_faiz_yuzde, taksit_sayisi, bas_tarih, para_birimi, durum, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'aktif', ?)"
        )->execute([$sozNo, $hesapId, (string)$hs['banka_ad'], $anapara, $faiz, $taksit, $basTar, (string)$hs['para_birimi'], $uid ?: null]);
        $kid = (int)$p->lastInsertId();

        // Eşit taksit (annuite) amortisman hesabı
        $r = ($faiz / 100.0) * (1.0 + $vergiOran);
        $aylikTaksit = $r > 0.0
            ? round($anapara * ($r * pow(1 + $r, $taksit)) / (pow(1 + $r, $taksit) - 1), 2)
            : round($anapara / $taksit, 2);

        $kalan = $anapara;
        $baseTs = strtotime($basTar);
        for ($i = 1; $i <= $taksit; $i++) {
            $vade = date('Y-m-d', strtotime("+$i month", $baseTs));
            $fTut = round($kalan * ($faiz / 100.0), 2);
            $vTut = round($fTut * $vergiOran, 2);
            $aTut = ($i === $taksit) ? round($kalan, 2) : round(max(0.0, $aylikTaksit - $fTut - $vTut), 2);
            if ($aTut > $kalan) $aTut = round($kalan, 2);
            $top  = round($aTut + $fTut + $vTut, 2);
            $kalan = round(max(0.0, $kalan - $aTut), 2);
            $p->prepare(
                "INSERT INTO `banka_kredi_taksitler` (kredi_id, taksit_no, vade_tarih, anapara_tutar, faiz_tutar, vergi_tutar, toplam_taksit, kalan_anapara, durum)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'bekliyor')"
            )->execute([$kid, $i, $vade, $aTut, $fTut, $vTut, $top, $kalan]);
        }

        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'banka_kredi', $kid, null, ['sozlesme' => $sozNo, 'anapara' => $anapara, 'taksit' => $taksit]);
        }
        return ['ok' => true, 'id' => $kid, 'taksit_tutari' => $aylikTaksit, 'mesaj' => "Kredi planı oluşturuldu ($sozNo · $taksit taksit)."];
    });
}

/** @return array{ok:bool,muh_fis_id?:?int,hata?:string,mesaj?:string,kod?:int} */
function banka_kredi_taksit_ode(PDO $pdo, int $taksitId, int $uid): array
{
    return db_tx($pdo, static function (PDO $p) use ($taksitId, $uid): array {
        $st = $p->prepare('SELECT t.*, k.sozlesme_no, k.hesap_id FROM `banka_kredi_taksitler` t INNER JOIN `banka_krediler` k ON k.id = t.kredi_id WHERE t.id = ?' . db_for_update($p));
        $st->execute([$taksitId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return ['ok' => false, 'hata' => 'Taksit bulunamadı.', 'kod' => 404];
        if ((string)$t['durum'] === 'odendi') return ['ok' => false, 'hata' => 'Bu taksit zaten ödenmiş.', 'kod' => 409];

        $bugun = date('Y-m-d');
        $fisId = null;
        if (function_exists('muh_hazir') && muh_hazir($p)) {
            $faizTop = round((float)$t['faiz_tutar'] + (float)$t['vergi_tutar'], 2);
            $satirlar = [
                ['hesap_kod' => '300', 'borc' => (float)$t['anapara_tutar'], 'alacak' => 0.0, 'aciklama' => $t['sozlesme_no'] . ' Anapara'],
            ];
            if ($faizTop > 0) {
                $satirlar[] = ['hesap_kod' => '780', 'borc' => $faizTop, 'alacak' => 0.0, 'aciklama' => $t['sozlesme_no'] . ' Faiz+Vergi'];
            }
            $satirlar[] = ['hesap_kod' => '102', 'borc' => 0.0, 'alacak' => (float)$t['toplam_taksit'], 'aciklama' => $t['sozlesme_no'] . ' Taksit #' . $t['taksit_no']];
            $fis = muh_fis_kaydet($p, [
                'tarih'    => $bugun,
                'tur'      => 'tediye',
                'aciklama' => 'Kredi Taksit Ödemesi — ' . $t['sozlesme_no'] . ' #' . $t['taksit_no'],
                'satirlar' => $satirlar,
            ], $uid);
            if (!empty($fis['ok'])) $fisId = (int)$fis['id'];
        }

        $p->prepare("UPDATE `banka_kredi_taksitler` SET durum='odendi', odeme_tarih=?, muh_fis_id=? WHERE id=?")
            ->execute([$bugun, $fisId, $taksitId]);
        return ['ok' => true, 'muh_fis_id' => $fisId, 'mesaj' => $t['sozlesme_no'] . ' Taksit #' . $t['taksit_no'] . ' ödendi.'];
    });
}

// ── 4) POS Komisyon & Valör / Bloke Çözümü ─────────────────────────────────

/** @return array{ok:bool,id?:int,net_tutar?:float,hata?:string,mesaj?:string,kod?:int} */
function banka_pos_kaydet(PDO $pdo, array $g, int $uid): array
{
    $hesapId = (int)($g['hesap_id'] ?? 0);
    $isTar   = trim((string)($g['islem_tarih'] ?? date('Y-m-d')));
    $valorGun = max(0, min(90, (int)($g['valor_gun'] ?? 1)));
    $brut    = round(erp_yon_sayi($g['brut_tutar'] ?? 0), 2);
    $komYuz  = max(0.0, min(25.0, round(erp_yon_sayi($g['komisyon_yuzde'] ?? 2.5), 3)));
    $kart    = mb_substr(trim((string)($g['kart_tipi'] ?? 'Visa/MC')), 0, 40, 'UTF-8');
    $ack     = mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 300, 'UTF-8');

    if ($hesapId <= 0) return ['ok' => false, 'hata' => 'POS bağlı banka hesabını seçin.', 'kod' => 422];
    if ($brut <= 0.0) return ['ok' => false, 'hata' => 'POS işlem tutarı sıfırdan büyük olmalıdır.', 'kod' => 422];
    if (!erp_yon_tarih_gecerli($isTar)) return ['ok' => false, 'hata' => 'Geçersiz işlem tarihi.', 'kod' => 422];

    $valTar = date('Y-m-d', strtotime("+$valorGun days", strtotime($isTar)));
    $komTut = round($brut * ($komYuz / 100.0), 2);
    $netTut = round($brut - $komTut, 2);

    $pdo->prepare(
        "INSERT INTO `banka_pos_hareketler` (hesap_id, islem_tarih, valor_tarih, kart_tipi, brut_tutar, komisyon_yuzde, komisyon_tutar, net_tutar, durum, aciklama)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'blokede', ?)"
    )->execute([$hesapId, $isTar, $valTar, $kart, $brut, $komYuz, $komTut, $netTut, $ack]);
    $id = (int)$pdo->lastInsertId();
    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'banka_pos', $id, null, ['brut' => $brut, 'komisyon' => $komTut, 'net' => $netTut, 'uid' => $uid]);
    }
    return ['ok' => true, 'id' => $id, 'net_tutar' => $netTut, 'mesaj' => "POS hareketi kaydedildi (Net: $netTut TL · Valör: $valTar)."];
}

/** @return array{ok:bool,hata?:string,mesaj?:string,kod?:int} */
function banka_pos_bloke_coz(PDO $pdo, int $posId, int $uid): array
{
    $st = $pdo->prepare('SELECT * FROM `banka_pos_hareketler` WHERE id = ?');
    $st->execute([$posId]);
    $pos = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pos) return ['ok' => false, 'hata' => 'POS hareketi bulunamadı.', 'kod' => 404];
    if ((string)$pos['durum'] === 'cozuldu') return ['ok' => false, 'hata' => 'POS blokesi zaten çözülmüş.', 'kod' => 409];

    $pdo->prepare("UPDATE `banka_pos_hareketler` SET durum='cozuldu', cozuldu_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $posId]);
    if (function_exists('audit_log_event')) {
        audit_log_event('resolve', 'banka_pos', $posId, ['durum' => 'blokede'], ['durum' => 'cozuldu', 'uid' => $uid]);
    }
    return ['ok' => true, 'mesaj' => 'POS blokesi çözüldü ve hesaba geçti (' . $pos['net_tutar'] . ' TL).'];
}

// ── Listeler ───────────────────────────────────────────────────────────────

/** @return list<array<string,mixed>> */
function banka_hesap_listesi(PDO $pdo): array
{
    return $pdo->query(
        'SELECT h.*,
                ROUND(h.acilis_bakiye + COALESCE((SELECT SUM(e.tutar) FROM `banka_ekstreler` e WHERE e.hesap_id = h.id), 0), 2) AS guncel_bakiye
         FROM `banka_hesaplari` h ORDER BY h.aktif DESC, h.banka_ad ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function banka_ekstre_listesi(PDO $pdo, int $hesapId = 0): array
{
    $w = $hesapId > 0 ? 'e.hesap_id = ?' : '1=1';
    $p = $hesapId > 0 ? [$hesapId] : [];
    $st = $pdo->prepare("SELECT e.*, h.kod AS hesap_kod, h.banka_ad FROM `banka_ekstreler` e INNER JOIN `banka_hesaplari` h ON h.id = e.hesap_id WHERE $w ORDER BY e.tarih DESC, e.id DESC LIMIT 200");
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function banka_kredi_listesi(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM `banka_krediler` ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $st = $pdo->prepare('SELECT * FROM `banka_kredi_taksitler` WHERE kredi_id = ? ORDER BY taksit_no ASC');
        $st->execute([(int)$r['id']]);
        $r['taksitler'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($r);
    return $rows;
}

/** @return list<array<string,mixed>> */
function banka_pos_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT p.*, h.kod AS hesap_kod, h.banka_ad FROM `banka_pos_hareketler` p INNER JOIN `banka_hesaplari` h ON h.id = p.hesap_id ORDER BY p.islem_tarih DESC, p.id DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC);
}
