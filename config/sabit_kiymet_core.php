<?php
// =========================================================
// config/sabit_kiymet_core.php — ERP-9 Sabit Kıymet, Normal/Azalan Amortisman (TDHP 770/257) & Bakım Yönetimi
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
function sabit_kiymet_tablolar(): array
{
    return ['sk_kartlar', 'sk_amortismanlar', 'sk_bakimlar'];
}

/** @return list<string> */
function sabit_kiymet_eksik_tablolar(PDO $pdo): array
{
    $eksik = [];
    foreach (sabit_kiymet_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $eksik[] = $t;
    }
    return $eksik;
}

function sabit_kiymet_hazir(PDO $pdo): bool
{
    return sabit_kiymet_eksik_tablolar($pdo) === [];
}

function sabit_kiymet_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = sabit_kiymet_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Sabit Kıymet şeması kurulu değil: ' . implode(', ', $eksik)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header')) render_header('Sabit Kıymet & Bakım');
    if (function_exists('erp_plan_head_varlik')) erp_plan_head_varlik();
    if (function_exists('erp_plan_sema_uyari')) {
        echo erp_plan_sema_uyari($eksik, 'sabit-kiymet', 'Sabit Kıymet, Amortisman & Bakım (ERP-9)');
    }
    if (function_exists('render_footer')) render_footer();
    exit;
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function sabit_kiymet_migrate(PDO $pdo): array
{
    $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $ai  = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'sk_kartlar' => "CREATE TABLE IF NOT EXISTS `sk_kartlar` (
            `id` $ai,
            `kod` VARCHAR(40) NOT NULL UNIQUE,
            `ad` VARCHAR(180) NOT NULL,
            `kategori` VARCHAR(30) NOT NULL DEFAULT 'makine',
            `seri_no` VARCHAR(80) NOT NULL DEFAULT '',
            `alis_tarih` DATE NOT NULL,
            `alis_bedeli` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `hurda_degeri` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `faydali_omur_yil` INT NOT NULL DEFAULT 5,
            `yontem` VARCHAR(20) NOT NULL DEFAULT 'normal',
            `zimmet_kisi` VARCHAR(120) NOT NULL DEFAULT '',
            `lokasyon` VARCHAR(120) NOT NULL DEFAULT '',
            `tdhp_varlik_kod` VARCHAR(20) NOT NULL DEFAULT '253',
            `tdhp_birikmis_kod` VARCHAR(20) NOT NULL DEFAULT '257',
            `tdhp_gider_kod` VARCHAR(20) NOT NULL DEFAULT '770',
            `durum` VARCHAR(20) NOT NULL DEFAULT 'aktif',
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'sk_amortismanlar' => "CREATE TABLE IF NOT EXISTS `sk_amortismanlar` (
            `id` $ai,
            `kart_id` INT NOT NULL,
            `yil` INT NOT NULL,
            `oran_yuzde` DECIMAL(6,2) NOT NULL DEFAULT 20.00,
            `donem_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `birikmis_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `net_defter_degeri` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `muh_fis_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(`kart_id`, `yil`)
        )$eng",
        'sk_bakimlar' => "CREATE TABLE IF NOT EXISTS `sk_bakimlar` (
            `id` $ai,
            `kart_id` INT NOT NULL,
            `bakim_tarih` DATE NOT NULL,
            `sonraki_bakim_tarih` DATE NULL,
            `tur` VARCHAR(24) NOT NULL DEFAULT 'periyodik',
            `maliyet` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `servis_firma` VARCHAR(160) NOT NULL DEFAULT '',
            `durum` VARCHAR(20) NOT NULL DEFAULT 'tamamlandi',
            `aciklama` VARCHAR(400) NOT NULL DEFAULT '',
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

// ── 1) Sabit Kıymet Kartı & Otomatik Amortisman Planı ─────────────────────

/**
 * Normal (eşit pay) veya Azalan Bakiyeler (VUK md.315 mükerrer: 2× normal oran, maks %50, son yılda bakiye kapanır).
 * @return list<array{yil:int,oran_yuzde:float,donem_tutar:float,birikmis_tutar:float,net_defter_degeri:float}>
 */
function sk_amortisman_plan_hesapla(float $alisBedeli, float $hurdaDegeri, int $omurYil, string $yontem, int $basYil): array
{
    $omur = max(1, min(50, $omurYil));
    $matrah = round(max(0.0, $alisBedeli - max(0.0, $hurdaDegeri)), 2);
    if ($matrah <= 0.0) return [];

    $normalOran = 100.0 / $omur;
    $oran = $yontem === 'azalan' ? min(50.0, round($normalOran * 2.0, 2)) : round($normalOran, 2);

    $plan = [];
    $birikmis = 0.0;
    $kalan = $matrah;
    for ($i = 0; $i < $omur; $i++) {
        $yil = $basYil + $i;
        if ($i === $omur - 1) {
            $tutar = round($kalan, 2);
        } elseif ($yontem === 'azalan') {
            $tutar = round($kalan * ($oran / 100.0), 2);
        } else {
            $tutar = round($matrah / $omur, 2);
        }
        if ($tutar > $kalan) $tutar = round($kalan, 2);
        $birikmis = round($birikmis + $tutar, 2);
        $kalan    = round(max(0.0, $matrah - $birikmis), 2);
        $plan[] = [
            'yil'               => $yil,
            'oran_yuzde'        => $oran,
            'donem_tutar'       => $tutar,
            'birikmis_tutar'    => $birikmis,
            'net_defter_degeri' => round($kalan + max(0.0, $hurdaDegeri), 2),
        ];
    }
    return $plan;
}

/** @return array{ok:bool,id?:int,kod?:string,plan_satir?:int,hata?:string,mesaj?:string,kod_http?:int} */
function sk_kart_kaydet(PDO $pdo, array $g, int $uid): array
{
    $kod    = mb_strtoupper(mb_substr(trim((string)($g['kod'] ?? '')), 0, 40, 'UTF-8'), 'UTF-8');
    $ad     = mb_substr(trim((string)($g['ad'] ?? '')), 0, 180, 'UTF-8');
    $kat    = (string)($g['kategori'] ?? 'makine');
    if (!in_array($kat, ['makine', 'arac', 'demirbas', 'bina', 'yazilim'], true)) $kat = 'makine';
    $seri   = mb_substr(trim((string)($g['seri_no'] ?? '')), 0, 80, 'UTF-8');
    $alisT  = trim((string)($g['alis_tarih'] ?? date('Y-m-d')));
    $bedel  = round(erp_plan_sayi($g['alis_bedeli'] ?? 0), 2);
    $hurda  = max(0.0, round(erp_plan_sayi($g['hurda_degeri'] ?? 0), 2));
    $omur   = max(1, min(50, (int)($g['faydali_omur_yil'] ?? 5)));
    $yontem = (string)($g['yontem'] ?? 'normal');
    if (!in_array($yontem, ['normal', 'azalan'], true)) $yontem = 'normal';
    $zimmet = mb_substr(trim((string)($g['zimmet_kisi'] ?? '')), 0, 120, 'UTF-8');
    $lok    = mb_substr(trim((string)($g['lokasyon'] ?? '')), 0, 120, 'UTF-8');

    $varlikMap = ['makine' => '253', 'arac' => '254', 'demirbas' => '255', 'bina' => '253', 'yazilim' => '255'];
    $tdhpVar = trim((string)($g['tdhp_varlik_kod'] ?? '')) ?: $varlikMap[$kat];
    $tdhpGid = trim((string)($g['tdhp_gider_kod'] ?? '')) ?: ($kat === 'makine' ? '730' : '770');

    if ($ad === '') return ['ok' => false, 'hata' => 'Sabit kıymet adı zorunludur.', 'kod' => 422];
    if (!erp_plan_tarih_gecerli($alisT)) return ['ok' => false, 'hata' => 'Geçersiz alış tarihi.', 'kod' => 422];
    if ($bedel <= 0.0) return ['ok' => false, 'hata' => 'Alış bedeli sıfırdan büyük olmalıdır.', 'kod' => 422];
    if ($hurda >= $bedel) return ['ok' => false, 'hata' => 'Hurda değeri alış bedelinden küçük olmalıdır.', 'kod' => 422];
    if ($kod === '') $kod = 'SK-' . date('ym', strtotime($alisT)) . '-' . random_int(100, 999);

    return db_tx($pdo, static function (PDO $p) use ($kod, $ad, $kat, $seri, $alisT, $bedel, $hurda, $omur, $yontem, $zimmet, $lok, $tdhpVar, $tdhpGid, $uid): array {
        $chk = $p->prepare('SELECT 1 FROM `sk_kartlar` WHERE kod = ?');
        $chk->execute([$kod]);
        if ($chk->fetchColumn()) return ['ok' => false, 'hata' => "Bu sabit kıymet kodu zaten kayıtlı ($kod).", 'kod' => 409];

        $p->prepare(
            "INSERT INTO `sk_kartlar` (kod, ad, kategori, seri_no, alis_tarih, alis_bedeli, hurda_degeri, faydali_omur_yil, yontem, zimmet_kisi, lokasyon, tdhp_varlik_kod, tdhp_birikmis_kod, tdhp_gider_kod, durum, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '257', ?, 'aktif', ?)"
        )->execute([$kod, $ad, $kat, $seri, $alisT, $bedel, $hurda, $omur, $yontem, $zimmet, $lok, $tdhpVar, $tdhpGid, $uid ?: null]);
        $kid = (int)$p->lastInsertId();

        $basYil = (int)substr($alisT, 0, 4);
        $plan = sk_amortisman_plan_hesapla($bedel, $hurda, $omur, $yontem, $basYil);
        foreach ($plan as $sat) {
            $p->prepare(
                'INSERT INTO `sk_amortismanlar` (kart_id, yil, oran_yuzde, donem_tutar, birikmis_tutar, net_defter_degeri) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$kid, $sat['yil'], $sat['oran_yuzde'], $sat['donem_tutar'], $sat['birikmis_tutar'], $sat['net_defter_degeri']]);
        }

        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'sabit_kiymet', $kid, null, ['kod' => $kod, 'bedel' => $bedel, 'omur' => $omur, 'yontem' => $yontem]);
        }
        return ['ok' => true, 'id' => $kid, 'sk_kod' => $kod, 'plan_satir' => count($plan), 'mesaj' => "Sabit kıymet ve $omur yıllık amortisman planı oluşturuldu ($kod)."];
    });
}

/**
 * Seçilen amortisman satırı için TDHP 770/730 (Borç) → 257 Birikmiş Amortismanlar (Alacak) yevmiye fişi keser (idempotent).
 * @return array{ok:bool,fis_id?:int,fis_no?:string,hata?:string,mesaj?:string,kod?:int}
 */
function sk_amortisman_muhasebelestir(PDO $pdo, int $amortismanId, int $uid): array
{
    if (!function_exists('muh_hazir') || !muh_hazir($pdo)) {
        return ['ok' => false, 'hata' => 'Genel Muhasebe (TDHP) tabloları kurulu değil.', 'kod' => 503];
    }
    return db_tx($pdo, static function (PDO $p) use ($amortismanId, $uid): array {
        $st = $p->prepare(
            'SELECT a.*, k.kod AS sk_kod, k.ad AS sk_ad, k.tdhp_gider_kod, k.tdhp_birikmis_kod
             FROM `sk_amortismanlar` a INNER JOIN `sk_kartlar` k ON k.id = a.kart_id
             WHERE a.id = ?' . db_for_update($p)
        );
        $st->execute([$amortismanId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) return ['ok' => false, 'hata' => 'Amortisman satırı bulunamadı.', 'kod' => 404];
        if (!empty($a['muh_fis_id'])) return ['ok' => false, 'hata' => 'Bu dönem amortismanı zaten muhasebeleştirilmiş.', 'kod' => 409];

        $tutar = round((float)$a['donem_tutar'], 2);
        if ($tutar <= 0.0) return ['ok' => false, 'hata' => 'Amortisman tutarı sıfır.', 'kod' => 422];

        $fis = muh_fis_kaydet($p, [
            'tarih'        => ((int)$a['yil']) . '-12-31',
            'tur'          => 'mahsup',
            'aciklama'     => $a['yil'] . ' Yılı Amortisman Gideri — ' . $a['sk_kod'] . ' ' . $a['sk_ad'],
            'kaynak_modul' => 'sabit_kiymet',
            'kaynak_id'    => $amortismanId,
            'satirlar'     => [
                ['hesap_kod' => (string)$a['tdhp_gider_kod'], 'borc' => $tutar, 'alacak' => 0.0, 'aciklama' => $a['sk_kod'] . ' Amortisman Gideri'],
                ['hesap_kod' => (string)$a['tdhp_birikmis_kod'], 'borc' => 0.0, 'alacak' => $tutar, 'aciklama' => $a['sk_kod'] . ' Birikmiş Amortisman'],
            ],
        ], $uid);
        if (empty($fis['ok'])) return $fis;

        $p->prepare('UPDATE `sk_amortismanlar` SET muh_fis_id = ? WHERE id = ?')->execute([(int)$fis['id'], $amortismanId]);
        return ['ok' => true, 'fis_id' => (int)$fis['id'], 'fis_no' => (string)$fis['fis_no'], 'mesaj' => $a['sk_kod'] . ' ' . $a['yil'] . ' amortismanı yevmiyeye işlendi (' . $fis['fis_no'] . ').'];
    });
}

// ── 2) Periyodik Bakım / Onarım ────────────────────────────────────────────

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function sk_bakim_kaydet(PDO $pdo, array $g, int $uid): array
{
    $kid   = (int)($g['kart_id'] ?? 0);
    $tarih = trim((string)($g['bakim_tarih'] ?? date('Y-m-d')));
    $sonr  = trim((string)($g['sonraki_bakim_tarih'] ?? ''));
    $tur   = (string)($g['tur'] ?? 'periyodik');
    if (!in_array($tur, ['periyodik', 'ariza', 'muayene', 'sigorta'], true)) $tur = 'periyodik';
    $mal   = max(0.0, round(erp_plan_sayi($g['maliyet'] ?? 0), 2));
    $serv  = mb_substr(trim((string)($g['servis_firma'] ?? '')), 0, 160, 'UTF-8');
    $dur   = (string)($g['durum'] ?? 'tamamlandi');
    if (!in_array($dur, ['planli', 'tamamlandi'], true)) $dur = 'tamamlandi';
    $ack   = mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 400, 'UTF-8');

    if ($kid <= 0 || !erp_plan_tarih_gecerli($tarih)) return ['ok' => false, 'hata' => 'Sabit kıymet ve bakım tarihi zorunludur.', 'kod' => 422];
    if ($sonr !== '' && !erp_plan_tarih_gecerli($sonr)) $sonr = '';

    $pdo->prepare(
        'INSERT INTO `sk_bakimlar` (kart_id, bakim_tarih, sonraki_bakim_tarih, tur, maliyet, servis_firma, durum, aciklama, olusturan_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$kid, $tarih, $sonr !== '' ? $sonr : null, $tur, $mal, $serv, $dur, $ack, $uid ?: null]);
    $id = (int)$pdo->lastInsertId();
    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'sk_bakim', $id, null, ['kart_id' => $kid, 'tur' => $tur, 'maliyet' => $mal]);
    }
    return ['ok' => true, 'id' => $id, 'mesaj' => 'Bakım / servis kaydı eklendi.'];
}

// ── Listeler ───────────────────────────────────────────────────────────────

/** @return list<array<string,mixed>> */
function sk_kart_listesi(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM `sk_kartlar` ORDER BY id DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $st = $pdo->prepare('SELECT * FROM `sk_amortismanlar` WHERE kart_id = ? ORDER BY yil ASC');
        $st->execute([(int)$r['id']]);
        $r['amortismanlar'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($r);
    return $rows;
}

/** @return list<array<string,mixed>> */
function sk_bakim_listesi(PDO $pdo): array
{
    return $pdo->query(
        'SELECT b.*, k.kod AS sk_kod, k.ad AS sk_ad FROM `sk_bakimlar` b INNER JOIN `sk_kartlar` k ON k.id = b.kart_id ORDER BY b.bakim_tarih DESC, b.id DESC LIMIT 150'
    )->fetchAll(PDO::FETCH_ASSOC);
}
