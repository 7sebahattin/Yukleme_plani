<?php
// =========================================================
// config/ik_bordro_core.php — ERP-7 İK Özlük, Yıllık İzin & Resmi Türkiye Bordro Motoru
//
// Mevcut PDKS (employees) ve Puantaj tablolarına ALTER yapmadan çalışan ekleyici İK/Bordro çekirdeği:
//   - ik_ozluk             : Kadrolu personel özlük, departman, unvan, brüt maaş, IBAN, kıdem
//   - ik_izinler           : Yıllık/Mazeret/Rapor/Ücretsiz izin + 4857 İş Kanunu bakiye hesabı + Onay Akışı bağı
//   - ik_bordro_donemler + ik_bordro_satirlar :
//       SGK İşçi %14 + İşsizlik İşçi %1 · Kümülatif GV Dilimleri (%15/%20/%27/%35/%40) · Damga %0.759
//       SGK İşveren %15.5 (5 puan indirimli) + İşsizlik İşveren %2 · Net Ödenecek & İşveren Maliyeti
//       + Genel Muhasebe 770 / 335 / 360 / 361 Tahakkuk Yevmiye Fişi
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/onay_core.php';

/** @return list<string> */
function ik_bordro_tablolar(): array
{
    return ['ik_ozluk', 'ik_izinler', 'ik_bordro_donemler', 'ik_bordro_satirlar'];
}

/** @return list<string> */
function ik_bordro_eksik_tablolar(PDO $pdo): array
{
    $e = [];
    foreach (ik_bordro_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $e[] = $t;
    }
    return $e;
}

function ik_bordro_hazir(PDO $pdo): bool
{
    return ik_bordro_eksik_tablolar($pdo) === [];
}

function ik_bordro_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = ik_bordro_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'İK & Bordro tabloları kurulmamış (' . implode(', ', $eksik) . '). migrate.php#ik-bordro kartını çalıştırın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header') && function_exists('erp_yon_sema_uyari')) {
        render_header('İK Özlük, İzin & Resmi Bordro');
        echo erp_yon_sema_uyari($eksik, 'ik-bordro', 'İK & Resmi Bordro / Özlük / İzin (ERP-7)');
        if (function_exists('render_footer')) render_footer();
        exit;
    }
    http_response_code(503);
    exit('İK & Bordro şeması kurulmamış.');
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function ik_bordro_migrate(PDO $pdo): array
{
    $sqlite = db_is_sqlite($pdo);
    $ai  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'ik_ozluk' => "CREATE TABLE IF NOT EXISTS `ik_ozluk` (
            `id` $ai,
            `employee_id` INT NULL,
            `sicil_no` VARCHAR(40) NOT NULL UNIQUE,
            `ad_soyad` VARCHAR(160) NOT NULL,
            `tc_kimlik` VARCHAR(11) NOT NULL DEFAULT '',
            `departman` VARCHAR(100) NOT NULL DEFAULT '',
            `unvan` VARCHAR(100) NOT NULL DEFAULT '',
            `ise_giris_tarih` DATE NOT NULL,
            `isten_cikis_tarih` DATE NULL,
            `brut_maas` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `iban` VARCHAR(40) NOT NULL DEFAULT '',
            `cocuk_sayisi` TINYINT NOT NULL DEFAULT 0,
            `aktif` TINYINT NOT NULL DEFAULT 1,
            `notlar` VARCHAR(400) NOT NULL DEFAULT '',
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'ik_izinler' => "CREATE TABLE IF NOT EXISTS `ik_izinler` (
            `id` $ai,
            `ozluk_id` INT NOT NULL,
            `tur` VARCHAR(30) NOT NULL DEFAULT 'yillik',
            `bas_tarih` DATE NOT NULL,
            `bit_tarih` DATE NOT NULL,
            `gun_sayisi` INT NOT NULL DEFAULT 1,
            `durum` VARCHAR(20) NOT NULL DEFAULT 'bekliyor',
            `onay_talep_id` INT NULL,
            `aciklama` VARCHAR(400) NOT NULL DEFAULT '',
            `karar_veren_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'ik_bordro_donemler' => "CREATE TABLE IF NOT EXISTS `ik_bordro_donemler` (
            `id` $ai,
            `donem_kodu` VARCHAR(10) NOT NULL UNIQUE,
            `yil` INT NOT NULL,
            `ay` INT NOT NULL,
            `toplam_brut` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `toplam_net` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `toplam_sgk_isci` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `toplam_sgk_isveren` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `toplam_gv` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `toplam_dv` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `toplam_maliyet` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `durum` VARCHAR(20) NOT NULL DEFAULT 'taslak',
            `muh_fis_id` INT NULL,
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'ik_bordro_satirlar' => "CREATE TABLE IF NOT EXISTS `ik_bordro_satirlar` (
            `id` $ai,
            `donem_id` INT NOT NULL,
            `ozluk_id` INT NOT NULL,
            `brut_ucret` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `fazla_mesai` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `sgk_isci` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `issizlik_isci` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `gv_matrah` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `kumulatif_gv_matrah` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `gelir_vergisi` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `damga_vergisi` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `avans_kesinti` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `net_ucret` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `sgk_isveren` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `issizlik_isveren` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `isveren_maliyeti` DECIMAL(16,2) NOT NULL DEFAULT 0.00
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

// ── 1) Özlük Kartı & 4857 Yıllık İzin Bakiye Motoru ───────────────────────

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function ik_ozluk_kaydet(PDO $pdo, array $g, int $uid): array
{
    $sicil  = mb_strtoupper(mb_substr(trim((string)($g['sicil_no'] ?? '')), 0, 40, 'UTF-8'), 'UTF-8');
    $ad     = mb_substr(trim((string)($g['ad_soyad'] ?? '')), 0, 160, 'UTF-8');
    $tc     = preg_replace('/\D/', '', (string)($g['tc_kimlik'] ?? '')) ?? '';
    $dept   = mb_substr(trim((string)($g['departman'] ?? '')), 0, 100, 'UTF-8');
    $unvan  = mb_substr(trim((string)($g['unvan'] ?? '')), 0, 100, 'UTF-8');
    $giris  = trim((string)($g['ise_giris_tarih'] ?? date('Y-m-d')));
    $brut   = max(0.0, round(erp_yon_sayi($g['brut_maas'] ?? 0), 2));
    $iban   = strtoupper(preg_replace('/\s+/', '', (string)($g['iban'] ?? '')) ?? '');
    $cocuk  = max(0, min(15, (int)($g['cocuk_sayisi'] ?? 0)));
    $empId  = isset($g['employee_id']) && (int)$g['employee_id'] > 0 ? (int)$g['employee_id'] : null;
    $notlar = mb_substr(trim((string)($g['notlar'] ?? '')), 0, 400, 'UTF-8');

    if ($ad === '') return ['ok' => false, 'hata' => 'Ad soyad zorunludur.', 'kod' => 422];
    if ($tc !== '' && strlen($tc) !== 11) return ['ok' => false, 'hata' => 'TC Kimlik numarası 11 haneli olmalıdır.', 'kod' => 422];
    if (!erp_yon_tarih_gecerli($giris)) return ['ok' => false, 'hata' => 'Geçersiz işe giriş tarihi.', 'kod' => 422];
    if ($brut <= 0.0) return ['ok' => false, 'hata' => 'Brüt maaş sıfırdan büyük olmalıdır.', 'kod' => 422];
    if ($sicil === '') $sicil = 'PRS-' . date('ym') . '-' . random_int(100, 999);

    return db_tx($pdo, static function (PDO $p) use ($empId, $sicil, $ad, $tc, $dept, $unvan, $giris, $brut, $iban, $cocuk, $notlar, $uid): array {
        $chk = $p->prepare('SELECT id FROM `ik_ozluk` WHERE sicil_no = ?');
        $chk->execute([$sicil]);
        if ($chk->fetchColumn()) return ['ok' => false, 'hata' => "Bu sicil numarası zaten kayıtlı ($sicil).", 'kod' => 409];

        $p->prepare(
            'INSERT INTO `ik_ozluk` (employee_id, sicil_no, ad_soyad, tc_kimlik, departman, unvan, ise_giris_tarih, brut_maas, iban, cocuk_sayisi, aktif, notlar)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([$empId, $sicil, $ad, $tc, $dept, $unvan, $giris, $brut, $iban, $cocuk, $notlar]);
        $id = (int)$p->lastInsertId();
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'ik_ozluk', $id, null, ['sicil' => $sicil, 'ad' => $ad, 'brut' => $brut, 'uid' => $uid]);
        }
        return ['ok' => true, 'id' => $id, 'mesaj' => "Özlük kartı oluşturuldu ($sicil — $ad)."];
    });
}

/**
 * 4857 sayılı İş Kanunu md. 53 kıdeme göre yıllık izin hak edişi & kalan bakiye hesabı:
 *   1–5 yıl (dahil): 14 gün/yıl · 5–15 yıl: 20 gün/yıl · 15+ yıl: 26 gün/yıl.
 * @return array{kidem_yil:int,toplam_hak:int,kullanilan:int,kalan:int}
 */
function ik_yillik_izin_bakiye(PDO $pdo, int $ozlukId, ?string $refTarih = null): array
{
    $st = $pdo->prepare('SELECT ise_giris_tarih FROM `ik_ozluk` WHERE id = ?');
    $st->execute([$ozlukId]);
    $giris = $st->fetchColumn();
    if (!$giris) return ['kidem_yil' => 0, 'toplam_hak' => 0, 'kullanilan' => 0, 'kalan' => 0];

    $d1 = new DateTimeImmutable((string)$giris);
    $d2 = new DateTimeImmutable($refTarih ?? date('Y-m-d'));
    $kidem = $d2 >= $d1 ? (int)$d1->diff($d2)->y : 0;

    $hak = 0;
    for ($y = 1; $y <= $kidem; $y++) {
        if ($y <= 5) $hak += 14;
        elseif ($y < 15) $hak += 20;
        else $hak += 26;
    }

    $stK = $pdo->prepare("SELECT COALESCE(SUM(gun_sayisi), 0) FROM `ik_izinler` WHERE ozluk_id = ? AND tur = 'yillik' AND durum = 'onaylandi'");
    $stK->execute([$ozlukId]);
    $kullanilan = (int)$stK->fetchColumn();
    return [
        'kidem_yil'  => $kidem,
        'toplam_hak' => $hak,
        'kullanilan' => $kullanilan,
        'kalan'      => $hak - $kullanilan,
    ];
}

/** @return array{ok:bool,id?:int,onay_talep_id?:?int,hata?:string,mesaj?:string,kod?:int} */
function ik_izin_talep_et(PDO $pdo, array $g, int $uid): array
{
    $ozlukId = (int)($g['ozluk_id'] ?? 0);
    $tur     = (string)($g['tur'] ?? 'yillik');
    $bas     = trim((string)($g['bas_tarih'] ?? ''));
    $bit     = trim((string)($g['bit_tarih'] ?? ''));
    $ack     = mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 400, 'UTF-8');

    if ($ozlukId <= 0) return ['ok' => false, 'hata' => 'Personel seçin.', 'kod' => 422];
    if (!in_array($tur, ['yillik', 'mazeret', 'rapor', 'ucretsiz'], true)) return ['ok' => false, 'hata' => 'Geçersiz izin türü.', 'kod' => 422];
    if (!erp_yon_tarih_gecerli($bas) || !erp_yon_tarih_gecerli($bit) || $bit < $bas) {
        return ['ok' => false, 'hata' => 'Geçersiz izin tarih aralığı.', 'kod' => 422];
    }

    $gun = (int)((strtotime($bit) - strtotime($bas)) / 86400) + 1;

    return db_tx($pdo, static function (PDO $p) use ($ozlukId, $tur, $bas, $bit, $gun, $ack, $uid): array {
        $st = $p->prepare('SELECT ad_soyad FROM `ik_ozluk` WHERE id = ?');
        $st->execute([$ozlukId]);
        $ad = $st->fetchColumn();
        if (!$ad) return ['ok' => false, 'hata' => 'Özlük kartı bulunamadı.', 'kod' => 404];

        $onayId = null;
        if (onay_hazir($p)) {
            $ot = onay_talep_olustur($p, [
                'modul'    => 'izin',
                'kaynak_id'=> $ozlukId,
                'baslik'   => "İzin Talebi: $ad ($gun gün $tur · $bas / $bit)",
                'tutar'    => 0,
                'aciklama' => $ack,
            ], $uid);
            if (!empty($ot['ok'])) $onayId = (int)$ot['id'];
        }

        $p->prepare(
            "INSERT INTO `ik_izinler` (ozluk_id, tur, bas_tarih, bit_tarih, gun_sayisi, durum, onay_talep_id, aciklama)
             VALUES (?, ?, ?, ?, ?, 'bekliyor', ?, ?)"
        )->execute([$ozlukId, $tur, $bas, $bit, $gun, $onayId, $ack]);
        $id = (int)$p->lastInsertId();
        return ['ok' => true, 'id' => $id, 'onay_talep_id' => $onayId, 'mesaj' => "İzin talebi oluşturuldu ($gun gün)."];
    });
}

/** @return array{ok:bool,hata?:string,mesaj?:string,kod?:int} */
function ik_izin_karar(PDO $pdo, int $izinId, string $karar, int $uid): array
{
    if (!in_array($karar, ['onaylandi', 'reddedildi'], true)) return ['ok' => false, 'hata' => 'Geçersiz izin kararı.', 'kod' => 422];
    $st = $pdo->prepare('SELECT * FROM `ik_izinler` WHERE id = ?');
    $st->execute([$izinId]);
    $iz = $st->fetch(PDO::FETCH_ASSOC);
    if (!$iz) return ['ok' => false, 'hata' => 'İzin kaydı bulunamadı.', 'kod' => 404];

    $pdo->prepare('UPDATE `ik_izinler` SET durum=?, karar_veren_id=? WHERE id=?')->execute([$karar, $uid ?: null, $izinId]);
    if (function_exists('audit_log_event')) {
        audit_log_event('decision', 'ik_izin', $izinId, ['durum' => $iz['durum']], ['durum' => $karar, 'uid' => $uid]);
    }
    return ['ok' => true, 'mesaj' => 'İzin talebi güncellendi (' . strtoupper($karar) . ').'];
}

// ── 2) Resmi Türkiye Bordro Hesap Motoru (SGK / GV Dilimleri / Damga) ──────

/**
 * Kümülatif Gelir Vergisi dilim hesabı (TR ücret dilimleri: %15 / %20 / %27 / %35 / %40).
 */
function ik_gelir_vergisi_dilim_hesapla(float $oncekiKumulatif, float $aylikMatrah): float
{
    if ($aylikMatrah <= 0.0) return 0.0;
    $dilimler = [
        [158000.0,  0.15],
        [330000.0,  0.20],
        [1200000.0, 0.27],
        [4300000.0, 0.35],
        [INF,       0.40],
    ];
    $bas = max(0.0, $oncekiKumulatif);
    $bit = $bas + $aylikMatrah;
    $oncekiUst = 0.0;
    $vergi = 0.0;
    foreach ($dilimler as [$ust, $oran]) {
        $segBas = max($bas, $oncekiUst);
        $segBit = min($bit, $ust);
        if ($segBit > $segBas) {
            $vergi += ($segBit - $segBas) * $oran;
        }
        $oncekiUst = $ust;
        if ($bit <= $ust) break;
    }
    return round($vergi, 2);
}

/**
 * Tek personel için resmi aylık bordro satırı hesaplar (saf deterministik fonksiyon).
 * @return array<string,float>
 */
function ik_bordro_hesapla_satir(float $brutUcret, float $fazlaMesai = 0.0, float $oncekiKumulatifGv = 0.0, float $avansKesinti = 0.0, float $asgariUcretBrut = 26005.50): array
{
    $brutTop = round(max(0.0, $brutUcret) + max(0.0, $fazlaMesai), 2);
    // SGK tavanı = 7.5 × asgari ücret brüt
    $sgkMatrah     = min($brutTop, round($asgariUcretBrut * 7.5, 2));
    $sgkIsci       = round($sgkMatrah * 0.14, 2);
    $issizlikIsci  = round($sgkMatrah * 0.01, 2);
    $gvMatrah      = round(max(0.0, $brutTop - $sgkIsci - $issizlikIsci), 2);

    // Gelir Vergisi - Asgari Ücret GV İstisnası (7349 sayılı Kanun)
    $brutGv        = ik_gelir_vergisi_dilim_hesapla($oncekiKumulatifGv, $gvMatrah);
    $asgariMatrah  = round($asgariUcretBrut * 0.85, 2);
    $asgariGvIst   = round($asgariMatrah * 0.15, 2);
    $gelirVergisi  = round(max(0.0, $brutGv - $asgariGvIst), 2);

    // Damga Vergisi (%0.759) - Asgari Ücret DV İstisnası
    $damgaVergisi  = round(max(0.0, ($brutTop - $asgariUcretBrut) * 0.00759), 2);

    $kesintiTop    = round($sgkIsci + $issizlikIsci + $gelirVergisi + $damgaVergisi + max(0.0, $avansKesinti), 2);
    $netUcret      = round(max(0.0, $brutTop - $kesintiTop), 2);

    // İşveren payları (5 puan Hazine teşviki sonrası SGK İşveren %15.5 + İşsizlik %2)
    $sgkIsveren      = round($sgkMatrah * 0.155, 2);
    $issizlikIsveren = round($sgkMatrah * 0.02, 2);
    $isverenMaliyet  = round($brutTop + $sgkIsveren + $issizlikIsveren, 2);

    return [
        'brut_ucret'          => round($brutUcret, 2),
        'fazla_mesai'         => round($fazlaMesai, 2),
        'sgk_isci'            => $sgkIsci,
        'issizlik_isci'       => $issizlikIsci,
        'gv_matrah'           => $gvMatrah,
        'kumulatif_gv_matrah' => round($oncekiKumulatifGv + $gvMatrah, 2),
        'gelir_vergisi'       => $gelirVergisi,
        'damga_vergisi'       => $damgaVergisi,
        'avans_kesinti'       => round(max(0.0, $avansKesinti), 2),
        'net_ucret'           => $netUcret,
        'sgk_isveren'         => $sgkIsveren,
        'issizlik_isveren'    => $issizlikIsveren,
        'isveren_maliyeti'    => $isverenMaliyet,
    ];
}

/**
 * Seçilen yıl-ay için tüm aktif özlük personelinin resmi bordrosunu üretir (idempotent).
 * @return array{ok:bool,id?:int,donem_kodu?:string,personel_sayisi?:int,toplam_net?:float,hata?:string,mesaj?:string,kod?:int}
 */
function ik_bordro_donem_uret(PDO $pdo, int $yil, int $ay, int $uid): array
{
    if ($yil < 2000 || $yil > 2099 || $ay < 1 || $ay > 12) {
        return ['ok' => false, 'hata' => 'Geçersiz bordro yıl/ay bilgisi.', 'kod' => 422];
    }
    $donemKodu = sprintf('%04d-%02d', $yil, $ay);

    return db_tx($pdo, static function (PDO $p) use ($donemKodu, $yil, $ay, $uid): array {
        $chk = $p->prepare('SELECT id FROM `ik_bordro_donemler` WHERE donem_kodu = ?' . db_for_update($p));
        $chk->execute([$donemKodu]);
        if ($chk->fetchColumn()) {
            return ['ok' => false, 'hata' => "$donemKodu dönemi bordrosu zaten üretilmiş.", 'kod' => 409];
        }

        $personeller = $p->query('SELECT * FROM `ik_ozluk` WHERE aktif = 1 ORDER BY sicil_no ASC')->fetchAll(PDO::FETCH_ASSOC);
        if ($personeller === []) return ['ok' => false, 'hata' => 'Bordro üretilecek aktif özlük kartı yok.', 'kod' => 422];

        $p->prepare("INSERT INTO `ik_bordro_donemler` (donem_kodu, yil, ay, durum, olusturan_id) VALUES (?, ?, ?, 'kesinlesti', ?)")
            ->execute([$donemKodu, $yil, $ay, $uid ?: null]);
        $did = (int)$p->lastInsertId();

        $tBrut = 0.0; $tNet = 0.0; $tSgkIsci = 0.0; $tSgkIsveren = 0.0; $tGv = 0.0; $tDv = 0.0; $tMal = 0.0;
        foreach ($personeller as $per) {
            $oid = (int)$per['id'];
            // Aynı yıl önceki ayların kümülatif GV matrahını oku
            $stKum = $p->prepare(
                'SELECT COALESCE(SUM(s.gv_matrah), 0)
                 FROM `ik_bordro_satirlar` s INNER JOIN `ik_bordro_donemler` d ON d.id = s.donem_id
                 WHERE s.ozluk_id = ? AND d.yil = ? AND d.ay < ?'
            );
            $stKum->execute([$oid, $yil, $ay]);
            $oncekiKum = (float)$stKum->fetchColumn();

            $b = ik_bordro_hesapla_satir((float)$per['brut_maas'], 0.0, $oncekiKum, 0.0);
            $p->prepare(
                'INSERT INTO `ik_bordro_satirlar` (donem_id, ozluk_id, brut_ucret, fazla_mesai, sgk_isci, issizlik_isci, gv_matrah, kumulatif_gv_matrah, gelir_vergisi, damga_vergisi, avans_kesinti, net_ucret, sgk_isveren, issizlik_isveren, isveren_maliyeti)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $did, $oid, $b['brut_ucret'], $b['fazla_mesai'], $b['sgk_isci'], $b['issizlik_isci'],
                $b['gv_matrah'], $b['kumulatif_gv_matrah'], $b['gelir_vergisi'], $b['damga_vergisi'],
                $b['avans_kesinti'], $b['net_ucret'], $b['sgk_isveren'], $b['issizlik_isveren'], $b['isveren_maliyeti'],
            ]);

            $tBrut       = round($tBrut + $b['brut_ucret'] + $b['fazla_mesai'], 2);
            $tNet        = round($tNet + $b['net_ucret'], 2);
            $tSgkIsci    = round($tSgkIsci + $b['sgk_isci'] + $b['issizlik_isci'], 2);
            $tSgkIsveren = round($tSgkIsveren + $b['sgk_isveren'] + $b['issizlik_isveren'], 2);
            $tGv         = round($tGv + $b['gelir_vergisi'], 2);
            $tDv         = round($tDv + $b['damga_vergisi'], 2);
            $tMal        = round($tMal + $b['isveren_maliyeti'], 2);
        }

        $p->prepare(
            'UPDATE `ik_bordro_donemler` SET toplam_brut=?, toplam_net=?, toplam_sgk_isci=?, toplam_sgk_isveren=?, toplam_gv=?, toplam_dv=?, toplam_maliyet=? WHERE id=?'
        )->execute([$tBrut, $tNet, $tSgkIsci, $tSgkIsveren, $tGv, $tDv, $tMal, $did]);

        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'ik_bordro', $did, null, ['donem' => $donemKodu, 'personel' => count($personeller), 'net' => $tNet]);
        }
        return ['ok' => true, 'id' => $did, 'donem_kodu' => $donemKodu, 'personel_sayisi' => count($personeller), 'toplam_net' => $tNet, 'mesaj' => "$donemKodu bordrosu üretildi (" . count($personeller) . " personel)."];
    });
}

/**
 * Bordro döneminin Genel Muhasebe (TDHP 770 / 335 / 360 / 361) tahakkuk yevmiye fişini keser (idempotent).
 * @return array{ok:bool,fis_id?:int,fis_no?:string,hata?:string,mesaj?:string,kod?:int}
 */
function ik_bordro_muhasebelestir(PDO $pdo, int $donemId, int $uid): array
{
    if (!function_exists('muh_hazir') || !muh_hazir($pdo)) {
        return ['ok' => false, 'hata' => 'Genel Muhasebe (TDHP) tabloları kurulu değil.', 'kod' => 503];
    }

    return db_tx($pdo, static function (PDO $p) use ($donemId, $uid): array {
        $st = $p->prepare('SELECT * FROM `ik_bordro_donemler` WHERE id = ?' . db_for_update($p));
        $st->execute([$donemId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) return ['ok' => false, 'hata' => 'Bordro dönemi bulunamadı.', 'kod' => 404];
        if (!empty($d['muh_fis_id'])) return ['ok' => false, 'hata' => 'Bu bordro dönemi zaten muhasebeleştirilmiş.', 'kod' => 409];

        $borcTop = round((float)$d['toplam_maliyet'], 2);
        $netTop  = round((float)$d['toplam_net'], 2);
        $vergiTop = round((float)$d['toplam_gv'] + (float)$d['toplam_dv'], 2);
        $sgkTop  = round((float)$d['toplam_sgk_isci'] + (float)$d['toplam_sgk_isveren'], 2);
        // Yuvarlama farkı varsa 335'e yedir (Borç = Alacak kesinliği)
        $alacakTop = round($netTop + $vergiTop + $sgkTop, 2);
        $fark = round($borcTop - $alacakTop, 2);
        if (abs($fark) > 0.0) $netTop = round($netTop + $fark, 2);

        $satirlar = [
            ['hesap_kod' => '770', 'borc' => $borcTop, 'alacak' => 0.0, 'aciklama' => $d['donem_kodu'] . ' Personel Ücret & SGK İşveren Gideri'],
            ['hesap_kod' => '335', 'borc' => 0.0, 'alacak' => $netTop, 'aciklama' => $d['donem_kodu'] . ' Personele Borçlar (Net Maaş)'],
        ];
        if ($vergiTop > 0) $satirlar[] = ['hesap_kod' => '360', 'borc' => 0.0, 'alacak' => $vergiTop, 'aciklama' => $d['donem_kodu'] . ' Ödenecek GV + DV'];
        if ($sgkTop > 0)   $satirlar[] = ['hesap_kod' => '361', 'borc' => 0.0, 'alacak' => $sgkTop, 'aciklama' => $d['donem_kodu'] . ' Ödenecek SGK + İşsizlik Primleri'];

        $tarih = date('Y-m-t', strtotime($d['donem_kodu'] . '-01'));
        $fis = muh_fis_kaydet($p, [
            'tarih'        => $tarih,
            'tur'          => 'mahsup',
            'aciklama'     => $d['donem_kodu'] . ' Resmi Ücret Bordrosu Tahakkuk Fişi',
            'kaynak_modul' => 'ik_bordro',
            'kaynak_id'    => $donemId,
            'satirlar'     => $satirlar,
        ], $uid);
        if (empty($fis['ok'])) return $fis;

        $p->prepare('UPDATE `ik_bordro_donemler` SET muh_fis_id = ? WHERE id = ?')->execute([(int)$fis['id'], $donemId]);
        return ['ok' => true, 'fis_id' => (int)$fis['id'], 'fis_no' => (string)$fis['fis_no'], 'mesaj' => $d['donem_kodu'] . ' bordro tahakkuku yevmiyeye işlendi (' . $fis['fis_no'] . ').'];
    });
}

// ── Listeler ───────────────────────────────────────────────────────────────

/** @return list<array<string,mixed>> */
function ik_ozluk_listesi(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM `ik_ozluk` ORDER BY aktif DESC, ad_soyad ASC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $iz = ik_yillik_izin_bakiye($pdo, (int)$r['id']);
        $r['kidem_yil'] = $iz['kidem_yil'];
        $r['izin_kalan'] = $iz['kalan'];
    }
    unset($r);
    return $rows;
}

/** @return list<array<string,mixed>> */
function ik_izin_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT i.*, o.sicil_no, o.ad_soyad, o.departman FROM `ik_izinler` i INNER JOIN `ik_ozluk` o ON o.id = i.ozluk_id ORDER BY i.id DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function ik_bordro_donem_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM `ik_bordro_donemler` ORDER BY donem_kodu DESC LIMIT 36')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function ik_bordro_satir_listesi(PDO $pdo, int $donemId): array
{
    $st = $pdo->prepare('SELECT s.*, o.sicil_no, o.ad_soyad, o.iban, o.departman FROM `ik_bordro_satirlar` s INNER JOIN `ik_ozluk` o ON o.id = s.ozluk_id WHERE s.donem_id = ? ORDER BY o.sicil_no ASC');
    $st->execute([$donemId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
