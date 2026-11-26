<?php
// =========================================================
// config/onay_core.php — ERP-5 Merkezi Onay Akışları Motoru (Approval Workflow Engine)
//
// Kapsam:
//   - Kural Motoru (onay_kurallari): modül + tutar aralığı + sıralı/paralel çok kademeli onay
//   - Vekalet / Delegasyon (onay_vekaletler): tarih aralıklı vekil atama
//   - Talepler & Adımlar (onay_talepleri, onay_adimlari): onay/red + gerekçe + audit izi
//   - Ayrıca ERP Faz 3 ('erp_yonetim') ortak yetki/HTTP yardımcılarını sağlar.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db_tx_helpers.php';
require_once __DIR__ . '/muhasebe_roller.php';

// ── ERP Faz 3 Ortak Yetki ve HTTP Yardımcıları ─────────────────────────────

function erp_yon_can(string $eylem = 'read'): bool
{
    return muhasebe_modul_can('erp_yonetim', $eylem);
}

function erp_yon_kapi(string $eylem = 'read'): void
{
    if (!erp_yon_can($eylem)) {
        if (function_exists('forbidden')) forbidden();
        http_response_code(403);
        exit('Bu işlem için yetkiniz yok.');
    }
}

function erp_yon_no_store(): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}

function erp_yon_json_istek(): bool
{
    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $acc = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return $xhr || str_contains($acc, 'application/json');
}

function erp_yon_geri_guvenli(string $geri, string $varsayilan = 'onay_merkezi.php'): string
{
    $geri = trim($geri);
    if ($geri === '') return $varsayilan;
    $yol = explode('?', $geri, 2)[0];
    if (!preg_match('/^(onay_merkezi|banka_yonetim|ik_bordro)\.php$/', $yol)) {
        return $varsayilan;
    }
    return $geri;
}

function erp_yon_cevap(array $sonuc, string $geri, string $basariMesaj, bool $json): never
{
    $ok  = !empty($sonuc['ok']);
    $kod = (int)($sonuc['kod'] ?? ($ok ? 200 : 422));
    if ($json) {
        http_response_code($kod);
        header('Content-Type: application/json; charset=utf-8');
        if ($ok && empty($sonuc['mesaj']) && $basariMesaj !== '') {
            $sonuc['mesaj'] = $basariMesaj;
        }
        echo json_encode($sonuc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (function_exists('set_flash')) {
        set_flash($ok ? 'success' : 'error', $ok ? ($sonuc['mesaj'] ?? $basariMesaj) : ($sonuc['hata'] ?? 'İşlem başarısız.'));
    }
    header('Location: ' . erp_yon_geri_guvenli($geri));
    exit;
}

function erp_yon_tarih_gecerli(string $t): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function erp_yon_sayi($v): float
{
    if (is_int($v) || is_float($v)) return round((float)$v, 4);
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    if (str_contains($s, ',') && str_contains($s, '.')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? round((float)$s, 4) : 0.0;
}

// ── Şema & Migrasyon (#onay-akislari) ──────────────────────────────────────

/** @return list<string> */
function onay_tablolar(): array
{
    return ['onay_kurallari', 'onay_talepleri', 'onay_adimlari', 'onay_vekaletler'];
}

/** @return list<string> */
function onay_eksik_tablolar(PDO $pdo): array
{
    $e = [];
    foreach (onay_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $e[] = $t;
    }
    return $e;
}

function onay_hazir(PDO $pdo): bool
{
    return onay_eksik_tablolar($pdo) === [];
}

function onay_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = onay_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Onay Akışları tabloları kurulmamış (' . implode(', ', $eksik) . '). migrate.php#onay-akislari kartını çalıştırın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header') && function_exists('erp_yon_sema_uyari')) {
        render_header('Onay Akışları Merkezi');
        echo erp_yon_sema_uyari($eksik, 'onay-akislari', 'Onay Akışları Motoru (ERP-5)');
        if (function_exists('render_footer')) render_footer();
        exit;
    }
    http_response_code(503);
    exit('Onay Akışları şeması kurulmamış.');
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function onay_migrate(PDO $pdo): array
{
    $sqlite = db_is_sqlite($pdo);
    $ai  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'onay_kurallari' => "CREATE TABLE IF NOT EXISTS `onay_kurallari` (
            `id` $ai,
            `modul` VARCHAR(40) NOT NULL,
            `ad` VARCHAR(160) NOT NULL,
            `min_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `maks_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `kip` VARCHAR(20) NOT NULL DEFAULT 'sirali',
            `onaycilar_json` TEXT NOT NULL,
            `aktif` TINYINT NOT NULL DEFAULT 1,
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'onay_talepleri' => "CREATE TABLE IF NOT EXISTS `onay_talepleri` (
            `id` $ai,
            `talep_no` VARCHAR(40) NOT NULL UNIQUE,
            `kural_id` INT NULL,
            `modul` VARCHAR(40) NOT NULL,
            `kaynak_id` INT NULL,
            `baslik` VARCHAR(200) NOT NULL,
            `tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `kip` VARCHAR(20) NOT NULL DEFAULT 'sirali',
            `mevcut_adim` INT NOT NULL DEFAULT 1,
            `toplam_adim` INT NOT NULL DEFAULT 1,
            `durum` VARCHAR(20) NOT NULL DEFAULT 'bekliyor',
            `aciklama` VARCHAR(500) NOT NULL DEFAULT '',
            `talep_eden_id` INT NULL,
            `tamamlandi_at` DATETIME NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'onay_adimlari' => "CREATE TABLE IF NOT EXISTS `onay_adimlari` (
            `id` $ai,
            `talep_id` INT NOT NULL,
            `adim_no` INT NOT NULL DEFAULT 1,
            `atandi_user_id` INT NOT NULL,
            `vekil_user_id` INT NULL,
            `karar` VARCHAR(20) NOT NULL DEFAULT 'bekliyor',
            `karar_veren_id` INT NULL,
            `karar_notu` VARCHAR(400) NOT NULL DEFAULT '',
            `karar_at` DATETIME NULL
        )$eng",
        'onay_vekaletler' => "CREATE TABLE IF NOT EXISTS `onay_vekaletler` (
            `id` $ai,
            `asil_user_id` INT NOT NULL,
            `vekil_user_id` INT NOT NULL,
            `bas_tarih` DATE NOT NULL,
            `bit_tarih` DATE NOT NULL,
            `aktif` TINYINT NOT NULL DEFAULT 1,
            `notlar` VARCHAR(300) NOT NULL DEFAULT '',
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

/** @return array<string,string> */
function onay_modulleri(): array
{
    return [
        'satinalma'    => 'Satın Alma Talebi / Siparişi',
        'masraf'       => 'Personel Masraf / Harcama',
        'kredi_asimi'  => 'Cari Kredi Limiti Aşımı',
        'fatura_iptal' => 'Fatura / Ödeme İptali',
        'iskonto'      => 'Yüksek İskonto Onayı',
        'izin'         => 'Personel İzin Talebi',
    ];
}

// ── 1) Kural & Vekalet Yönetimi ────────────────────────────────────────────

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function onay_kural_kaydet(PDO $pdo, array $g, int $uid): array
{
    $modul = (string)($g['modul'] ?? '');
    $ad    = mb_substr(trim((string)($g['ad'] ?? '')), 0, 160, 'UTF-8');
    $min   = max(0.0, round(erp_yon_sayi($g['min_tutar'] ?? 0), 2));
    $maks  = max(0.0, round(erp_yon_sayi($g['maks_tutar'] ?? 0), 2));
    $kip   = (string)($g['kip'] ?? 'sirali');
    if (!in_array($kip, ['sirali', 'paralel'], true)) $kip = 'sirali';

    $rawOnaycilar = $g['onaycilar'] ?? '';
    $onayciList = [];
    if (is_array($rawOnaycilar)) {
        foreach ($rawOnaycilar as $x) if ((int)$x > 0) $onayciList[] = (int)$x;
    } else {
        foreach (preg_split('/[\s,;]+/', trim((string)$rawOnaycilar)) ?: [] as $x) {
            if ((int)$x > 0) $onayciList[] = (int)$x;
        }
    }
    $onayciList = array_values(array_unique($onayciList));

    if (!isset(onay_modulleri()[$modul])) return ['ok' => false, 'hata' => 'Geçersiz onay modülü.', 'kod' => 422];
    if ($ad === '') return ['ok' => false, 'hata' => 'Kural adı zorunludur.', 'kod' => 422];
    if ($maks > 0.0 && $maks < $min) return ['ok' => false, 'hata' => 'Maksimum tutar minimum tutardan küçük olamaz.', 'kod' => 422];
    if ($onayciList === []) return ['ok' => false, 'hata' => 'En az bir onaylayıcı kullanıcı ID girin (örn. 1, 2).', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($modul, $ad, $min, $maks, $kip, $onayciList, $uid): array {
        $p->prepare(
            'INSERT INTO `onay_kurallari` (modul, ad, min_tutar, maks_tutar, kip, onaycilar_json, aktif, olusturan_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([$modul, $ad, $min, $maks, $kip, json_encode($onayciList), $uid ?: null]);
        $id = (int)$p->lastInsertId();
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'onay_kural', $id, null, ['modul' => $modul, 'min' => $min, 'maks' => $maks, 'kip' => $kip]);
        }
        return ['ok' => true, 'id' => $id, 'mesaj' => "Onay kuralı kaydedildi ($ad)."];
    });
}

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function onay_vekalet_kaydet(PDO $pdo, array $g, int $uid): array
{
    $asil  = (int)($g['asil_user_id'] ?? $uid);
    $vekil = (int)($g['vekil_user_id'] ?? 0);
    $bas   = trim((string)($g['bas_tarih'] ?? date('Y-m-d')));
    $bit   = trim((string)($g['bit_tarih'] ?? date('Y-m-d', strtotime('+7 days'))));
    $not   = mb_substr(trim((string)($g['notlar'] ?? '')), 0, 300, 'UTF-8');

    if ($asil <= 0 || $vekil <= 0) return ['ok' => false, 'hata' => 'Asil ve vekil kullanıcı ID zorunludur.', 'kod' => 422];
    if ($asil === $vekil) return ['ok' => false, 'hata' => 'Kullanıcı kendisine vekalet veremez.', 'kod' => 422];
    if (!erp_yon_tarih_gecerli($bas) || !erp_yon_tarih_gecerli($bit) || $bit < $bas) {
        return ['ok' => false, 'hata' => 'Geçersiz vekalet tarih aralığı.', 'kod' => 422];
    }

    return db_tx($pdo, static function (PDO $p) use ($asil, $vekil, $bas, $bit, $not): array {
        $p->prepare('INSERT INTO `onay_vekaletler` (asil_user_id, vekil_user_id, bas_tarih, bit_tarih, aktif, notlar) VALUES (?, ?, ?, ?, 1, ?)')
            ->execute([$asil, $vekil, $bas, $bit, $not]);
        $id = (int)$p->lastInsertId();
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'onay_vekalet', $id, null, ['asil' => $asil, 'vekil' => $vekil, 'bas' => $bas, 'bit' => $bit]);
        }
        return ['ok' => true, 'id' => $id, 'mesaj' => "Vekalet kaydedildi (#$asil → #$vekil)."];
    });
}

function onay_aktif_vekil_bul(PDO $pdo, int $asilUserId, ?string $tarih = null): ?int
{
    $t = $tarih ?? date('Y-m-d');
    $st = $pdo->prepare('SELECT vekil_user_id FROM `onay_vekaletler` WHERE asil_user_id = ? AND aktif = 1 AND bas_tarih <= ? AND bit_tarih >= ? ORDER BY id DESC LIMIT 1');
    $st->execute([$asilUserId, $t, $t]);
    $v = $st->fetchColumn();
    return $v ? (int)$v : null;
}

// ── 2) Onay Talebi Oluşturma & Karar Verme ─────────────────────────────────

/** @return array{ok:bool,id?:int,talep_no?:string,adim_sayisi?:int,hata?:string,mesaj?:string,kod?:int} */
function onay_talep_olustur(PDO $pdo, array $g, int $uid): array
{
    $modul   = (string)($g['modul'] ?? '');
    $kaynakId = isset($g['kaynak_id']) && (int)$g['kaynak_id'] > 0 ? (int)$g['kaynak_id'] : null;
    $baslik  = mb_substr(trim((string)($g['baslik'] ?? '')), 0, 200, 'UTF-8');
    $tutar   = max(0.0, round(erp_yon_sayi($g['tutar'] ?? 0), 2));
    $pb      = strtoupper(trim((string)($g['para_birimi'] ?? 'TRY')));
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';
    $ack     = mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 500, 'UTF-8');

    if (!isset(onay_modulleri()[$modul])) return ['ok' => false, 'hata' => 'Geçersiz onay modülü.', 'kod' => 422];
    if ($baslik === '') return ['ok' => false, 'hata' => 'Onay talebi başlığı zorunludur.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($modul, $kaynakId, $baslik, $tutar, $pb, $ack, $uid): array {
        // Modül ve tutara uyan en spesifik kuralı seç
        $stK = $p->prepare(
            'SELECT * FROM `onay_kurallari`
             WHERE modul = ? AND aktif = 1 AND min_tutar <= ? AND (maks_tutar = 0 OR maks_tutar >= ?)
             ORDER BY min_tutar DESC, id DESC LIMIT 1'
        );
        $stK->execute([$modul, $tutar, $tutar]);
        $kural = $stK->fetch(PDO::FETCH_ASSOC);

        $kuralId = $kural ? (int)$kural['id'] : null;
        $kip     = $kural ? (string)$kural['kip'] : 'sirali';
        $onaycilar = $kural ? (array)json_decode((string)$kural['onaycilar_json'], true) : [];
        $onaycilar = array_values(array_filter(array_map('intval', $onaycilar), static fn(int $x): bool => $x > 0));
        if ($onaycilar === []) $onaycilar = [max(1, $uid)];

        $yil = date('Y');
        $stN = $p->prepare('SELECT COUNT(*) FROM `onay_talepleri` WHERE talep_no LIKE ?');
        $stN->execute(["ONY-$yil-%"]);
        $n = (int)$stN->fetchColumn() + 1;
        $talepNo = sprintf('ONY-%s-%05d', $yil, $n);
        while (true) {
            $c = $p->prepare('SELECT 1 FROM `onay_talepleri` WHERE talep_no = ?');
            $c->execute([$talepNo]);
            if (!$c->fetchColumn()) break;
            $n++;
            $talepNo = sprintf('ONY-%s-%05d', $yil, $n);
        }

        $p->prepare(
            "INSERT INTO `onay_talepleri` (talep_no, kural_id, modul, kaynak_id, baslik, tutar, para_birimi, kip, mevcut_adim, toplam_adim, durum, aciklama, talep_eden_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 'bekliyor', ?, ?)"
        )->execute([$talepNo, $kuralId, $modul, $kaynakId, $baslik, $tutar, $pb, $kip, count($onaycilar), $ack, $uid ?: null]);
        $tid = (int)$p->lastInsertId();

        foreach ($onaycilar as $idx => $onayciId) {
            $vekilId = onay_aktif_vekil_bul($p, $onayciId);
            $p->prepare(
                "INSERT INTO `onay_adimlari` (talep_id, adim_no, atandi_user_id, vekil_user_id, karar) VALUES (?, ?, ?, ?, 'bekliyor')"
            )->execute([$tid, $idx + 1, $onayciId, $vekilId]);
        }

        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'onay_talep', $tid, null, ['talep_no' => $talepNo, 'modul' => $modul, 'tutar' => $tutar, 'adim' => count($onaycilar)]);
        }
        return ['ok' => true, 'id' => $tid, 'talep_no' => $talepNo, 'adim_sayisi' => count($onaycilar), 'mesaj' => "Onay talebi oluşturuldu ($talepNo)."];
    });
}

/**
 * Onay adımında karar verir (sıralı/paralel + vekalet/admin yetkisi destekli).
 * @return array{ok:bool,durum?:string,hata?:string,mesaj?:string,kod?:int}
 */
function onay_karar_ver(PDO $pdo, int $talepId, string $karar, string $not, int $userId, bool $adminBypass = false): array
{
    if (!in_array($karar, ['onay', 'red'], true)) return ['ok' => false, 'hata' => 'Karar onay veya red olmalıdır.', 'kod' => 422];
    $not = mb_substr(trim($not), 0, 400, 'UTF-8');
    if ($karar === 'red' && $not === '') return ['ok' => false, 'hata' => 'Red işlemi için gerekçe yazmalısınız.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($talepId, $karar, $not, $userId, $adminBypass): array {
        $stT = $p->prepare('SELECT * FROM `onay_talepleri` WHERE id = ?' . db_for_update($p));
        $stT->execute([$talepId]);
        $talep = $stT->fetch(PDO::FETCH_ASSOC);
        if (!$talep) return ['ok' => false, 'hata' => 'Onay talebi bulunamadı.', 'kod' => 404];
        if ((string)$talep['durum'] !== 'bekliyor') return ['ok' => false, 'hata' => 'Bu talep zaten sonuçlanmış (' . $talep['durum'] . ').', 'kod' => 409];

        $stA = $p->prepare("SELECT * FROM `onay_adimlari` WHERE talep_id = ? AND karar = 'bekliyor' ORDER BY adim_no ASC" . db_for_update($p));
        $stA->execute([$talepId]);
        $bekleyenler = $stA->fetchAll(PDO::FETCH_ASSOC);
        if ($bekleyenler === []) return ['ok' => false, 'hata' => 'Bekleyen onay adımı kalmamış.', 'kod' => 409];

        $hedefAdim = null;
        if ((string)$talep['kip'] === 'sirali') {
            $ilk = $bekleyenler[0];
            $yetkili = ((int)$ilk['atandi_user_id'] === $userId)
                || ((int)($ilk['vekil_user_id'] ?? 0) === $userId)
                || (onay_aktif_vekil_bul($p, (int)$ilk['atandi_user_id']) === $userId)
                || $adminBypass;
            if (!$yetkili) return ['ok' => false, 'hata' => 'Sıradaki onay adımı (' . $ilk['adim_no'] . ') size veya vekaletinize atanmamış.', 'kod' => 403];
            $hedefAdim = $ilk;
        } else {
            foreach ($bekleyenler as $ad) {
                $yetkili = ((int)$ad['atandi_user_id'] === $userId)
                    || ((int)($ad['vekil_user_id'] ?? 0) === $userId)
                    || (onay_aktif_vekil_bul($p, (int)$ad['atandi_user_id']) === $userId);
                if ($yetkili) { $hedefAdim = $ad; break; }
            }
            if ($hedefAdim === null && $adminBypass) $hedefAdim = $bekleyenler[0];
            if ($hedefAdim === null) return ['ok' => false, 'hata' => 'Bu paralel onay talebinde bekleyen adımınız yok.', 'kod' => 403];
        }

        $now = date('Y-m-d H:i:s');
        $p->prepare('UPDATE `onay_adimlari` SET karar=?, karar_veren_id=?, karar_notu=?, karar_at=? WHERE id=?')
            ->execute([$karar, $userId, $not, $now, (int)$hedefAdim['id']]);

        $yeniDurum = 'bekliyor';
        if ($karar === 'red') {
            $yeniDurum = 'reddedildi';
            $p->prepare("UPDATE `onay_talepleri` SET durum='reddedildi', tamamlandi_at=? WHERE id=?")->execute([$now, $talepId]);
        } else {
            $kalan = (int)$p->query("SELECT COUNT(*) FROM `onay_adimlari` WHERE talep_id = " . $talepId . " AND karar = 'bekliyor'")->fetchColumn();
            if ($kalan === 0) {
                $yeniDurum = 'onaylandi';
                $p->prepare("UPDATE `onay_talepleri` SET durum='onaylandi', mevcut_adim=toplam_adim, tamamlandi_at=? WHERE id=?")->execute([$now, $talepId]);
            } else {
                $sonraki = (int)$p->query("SELECT MIN(adim_no) FROM `onay_adimlari` WHERE talep_id = " . $talepId . " AND karar = 'bekliyor'")->fetchColumn();
                $p->prepare('UPDATE `onay_talepleri` SET mevcut_adim=? WHERE id=?')->execute([$sonraki, $talepId]);
            }
        }

        if (function_exists('audit_log_event')) {
            audit_log_event($karar, 'onay_talep', $talepId, ['durum' => 'bekliyor'], ['adim' => $hedefAdim['adim_no'], 'durum' => $yeniDurum, 'karar_veren' => $userId]);
        }
        return ['ok' => true, 'durum' => $yeniDurum, 'mesaj' => $talep['talep_no'] . ' için karar işlendi (' . strtoupper($yeniDurum) . ').'];
    });
}

// ── Listeler ───────────────────────────────────────────────────────────────

/** @return list<array<string,mixed>> */
function onay_kural_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM `onay_kurallari` ORDER BY modul ASC, min_tutar ASC')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function onay_vekalet_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM `onay_vekaletler` ORDER BY bit_tarih DESC, id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function onay_talep_listesi(PDO $pdo, ?string $durum = null): array
{
    $w = '1=1'; $p = [];
    if ($durum !== null && $durum !== '') { $w = 'durum = ?'; $p[] = $durum; }
    $st = $pdo->prepare("SELECT * FROM `onay_talepleri` WHERE $w ORDER BY id DESC LIMIT 150");
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $stA = $pdo->prepare('SELECT * FROM `onay_adimlari` WHERE talep_id = ? ORDER BY adim_no ASC');
        $stA->execute([(int)$r['id']]);
        $r['adimlar'] = $stA->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($r);
    return $rows;
}
