<?php
// =========================================================
// v354 — TERS KART TAKİP (bağımsız, paralel günlük işçi giriş-çıkış modülü)
// =========================================================
// Sabah her işçiyi tek tek okutmak yerine, çavuşun KALICI kart havuzundan
// dağıtılmadan ELDE KALAN kartlar okutulur; aradaki fark (UID bazında) o gün
// dağıtılan kartlardır. Onayla birlikte dağıtılan her kart için, çavuşa
// tanımlı SABİT giriş saatiyle (ör. 08:00) açık bir çalışma dönemi yazılır.
// Akşam kartlar mevcut ORTAK ÇIKIŞ ile gerçek sunucu saatiyle çıkar.
//
// ⚠ İKİNCİ YAZMA YOLU YOK:
//   • Giriş dönemi/olayı → pdks_faz8j_satir_yaz() (TEK INSERT yeri; kaynak 'ters').
//     Kart kuralları → pdks_faz8j_satir_kontrol() (kiosk ile AYNI açık-dönem /
//     aynı-gün-eksik-çıkış / tanım / çakışma kuralları).
//   • Mesai → pdks_faz8j_oturum_coz() → pdks_gunluk_oturum_ac_veya_getir() (kiosk yolu).
//   • Çıkış → pdks_gunluk_ortak_cikis_kaydet() (değiştirilmedi).
//   • Geri alma → pdks_faz8j_void_uygula() (dönem SİLİNMEZ, iptal edilir).
//   • Kart durumu → pdks_gunluk_kart_durum_degistir().
//   Bu dosyada daily_worker_work_periods / daily_worker_card_events'e doğrudan
//   INSERT/UPDATE YOKTUR (test denetler).
//
// ⚠ ZAMANLAR karıştırılmaz: dönemin entry_time'ı = sabit giriş saati; GİRİŞ
//   olayının server_event_time'ı = GERÇEK kayıt anı (source='ters' — fiziksel
//   okutma DEĞİL); onay anı ters_sayim.onay_zamani'nda.
//
// ⚠ Tablolar OPSİYONELDİR (sahip GO verdi — YALNIZ yeni tablolar, ALTER YOK):
//   genel sema_hazir fonksiyonlarına EKLENMEZ; tablo yoksa modül gizli, eski
//   sistem aynen çalışır. Kurulum yalnız migrate.php kartından.
//
// Sahip kararları (2026-10-13): ayrı havuz · başka çavuşa tanımlı kart havuza
// giremez, havuzdaki kart başka çavuşun normal girişine giremez · saat çavuş+depo
// bazlı (tarihli), onayda gerekçeyle değişebilir · aynı gün normal + ters serbest
// (kart bazlı kilit) · dönmemiş kart karar verilmeden onayı ENGELLER · beyan sayısı
// isteğe bağlı, fark uyarı · onay sonrası geri alma ilk çıkışa kadar operatör,
// sonrası yalnız yönetici (Mesai Detayı) · sabit saat onaydan en fazla 2 sa ileride.
declare(strict_types=1);
require_once __DIR__ . '/pdks_gunluk.php';
require_once __DIR__ . '/pdks_faz8j.php';

const PDKS_TERS_KAYNAK = 'ters';
const PDKS_TERS_ILERI_DK = 120;          // sabit saat onay anından en fazla bu kadar ileride olabilir
const PDKS_TERS_HAVUZ_LIMIT = 500;       // çavuş başına aktif havuz kartı
const PDKS_TERS_GEREKCE_MAX = 500;
const PDKS_TERS_ESZAMANLI = 'Aynı anda başka bir işlem yapıldı — hiçbir şey yazılmadı. Sayfayı yenileyip tekrar deneyin.';
const PDKS_TERS_SAYIM_NOTU = 'Giriş Tanımlı — sabah kalan kart sayımı';
// v363 — MANUEL SAYIM: kart okutmadan elde kalan kart SAYISI girilir. Şema DEĞİŞMEDİ:
// ters_sayim.durum = 'manuel' (VARCHAR(20)) onaylı manuel sayımdır; aktif_anahtar dolu kalır.
const PDKS_TERS_DURUM_MANUEL = 'manuel';
const PDKS_TERS_ROL_MANUEL_CIKIS = 'manuel_cikis';
const PDKS_TERS_MANUEL_NOTU = 'Giriş Tanımlı — manuel sayım (çıkışta kayıt)';

/** Onaylı sayım mı (kartla 'onayli' YA DA manuel 'manuel'). Durum kontrolü için TEK yer. */
function pdks_ters_sayim_onayli_mi(array $s): bool
{
    return in_array((string)($s['durum'] ?? ''), ['onayli', PDKS_TERS_DURUM_MANUEL], true);
}
/** Manuel (kartsız) onaylı sayım mı. */
function pdks_ters_sayim_manuel_mi(array $s): bool { return (string)($s['durum'] ?? '') === PDKS_TERS_DURUM_MANUEL; }
/** Aktif (iptal/geri alınmamış) sayım durumları — SQL IN listesi için. */
function pdks_ters_aktif_durumlar(): array { return ['taslak', 'onayli', PDKS_TERS_DURUM_MANUEL]; }

/** Kart işlem etiketi → worker_cards.status (+ ters havuz etiketi). */
function pdks_ters_kart_islemleri(): array
{
    return [
        'kayip'         => ['status' => 'lost',      'etiket' => 'kayip',         'ad' => 'Kayıp'],
        'bozuk'         => ['status' => 'disabled',  'etiket' => 'bozuk',         'ad' => 'Bozuk'],
        'kullanim_disi' => ['status' => 'disabled',  'etiket' => 'kullanim_disi', 'ad' => 'Kullanım dışı'],
        'kullanima_al'  => ['status' => 'available', 'etiket' => null,            'ad' => 'Kullanımda'],
    ];
}

// =========================================================
// ŞEMA
// =========================================================

/** @return array<string,string> Sıra FK bağımlılığına göredir. */
function pdks_ters_tablolar(): array
{
    $t = [];
    // Çavuş kart havuzu üyeliği. Kart başına TEK aktif üyelik: aktif_kart_id
    // (aktifken = kart id, bitince NULL) UNIQUE. Geçmiş SİLİNMEZ (valid_to/end_reason).
    $t['ters_havuz_kart'] = "CREATE TABLE IF NOT EXISTS `ters_havuz_kart` (
        `id`                 INT AUTO_INCREMENT PRIMARY KEY,
        `foreman_id`         INT          NOT NULL,
        `depo`               VARCHAR(150) NOT NULL,
        `worker_card_id`     INT          NOT NULL,
        `worker_type_id`     INT          NOT NULL,
        `aktif_kart_id`      INT          NULL DEFAULT NULL,
        `valid_from`         DATETIME     NOT NULL,
        `valid_to`           DATETIME     NULL DEFAULT NULL,
        `durum_etiketi`      VARCHAR(20)  NULL DEFAULT NULL,
        `durum_notu`         VARCHAR(255) NULL DEFAULT NULL,
        `durum_zamani`       DATETIME     NULL DEFAULT NULL,
        `created_by_user_id` INT          NULL DEFAULT NULL,
        `ended_by_user_id`   INT          NULL DEFAULT NULL,
        `end_reason`         VARCHAR(255) NULL DEFAULT NULL,
        `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_thk_aktif_kart` (`aktif_kart_id`),
        INDEX `idx_thk_cavus` (`foreman_id`, `depo`),
        INDEX `idx_thk_kart` (`worker_card_id`),
        CONSTRAINT `fk_thk_card` FOREIGN KEY (`worker_card_id`)
            REFERENCES `worker_cards`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
        CONSTRAINT `fk_thk_foreman` FOREIGN KEY (`foreman_id`)
            REFERENCES `foremen`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
        CONSTRAINT `fk_thk_type` FOREIGN KEY (`worker_type_id`)
            REFERENCES `worker_types`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Sabit giriş saati — çavuş + depo, tarihli (en büyük valid_from ≤ gün geçerli).
    $t['ters_cavus_ayar'] = "CREATE TABLE IF NOT EXISTS `ters_cavus_ayar` (
        `id`                 INT AUTO_INCREMENT PRIMARY KEY,
        `foreman_id`         INT          NOT NULL,
        `depo`               VARCHAR(150) NOT NULL,
        `giris_saati`        CHAR(5)      NOT NULL,
        `valid_from`         DATE         NOT NULL,
        `reason`             VARCHAR(255) NULL DEFAULT NULL,
        `created_by_user_id` INT          NULL DEFAULT NULL,
        `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_tca_cavus` (`foreman_id`, `valid_from`),
        CONSTRAINT `fk_tca_foreman` FOREIGN KEY (`foreman_id`)
            REFERENCES `foremen`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Günlük sayım. Çavuş + gün + depo başına TEK aktif (taslak/onaylı) sayım:
    // aktif_anahtar UNIQUE, geri alınınca NULL (satır SİLİNMEZ).
    $t['ters_sayim'] = "CREATE TABLE IF NOT EXISTS `ters_sayim` (
        `id`                     INT AUTO_INCREMENT PRIMARY KEY,
        `foreman_id`             INT          NOT NULL,
        `depo`                   VARCHAR(150) NOT NULL,
        `work_date`              DATE         NOT NULL,
        `durum`                  VARCHAR(20)  NOT NULL DEFAULT 'taslak',
        `aktif_anahtar`          VARCHAR(80)  NULL DEFAULT NULL,
        `session_id`             INT          NULL DEFAULT NULL,
        `varsayilan_giris_saati` CHAR(5)      NULL DEFAULT NULL,
        `giris_saati`            CHAR(5)      NULL DEFAULT NULL,
        `giris_zamani`           DATETIME     NULL DEFAULT NULL,
        `giris_saati_nedeni`     VARCHAR(255) NULL DEFAULT NULL,
        `havuz_aktif`            INT          NOT NULL DEFAULT 0,
        `kalan_adet`             INT          NOT NULL DEFAULT 0,
        `dagitilan_adet`         INT          NOT NULL DEFAULT 0,
        `yeni_giris_adet`        INT          NOT NULL DEFAULT 0,
        `zaten_iceride_adet`     INT          NOT NULL DEFAULT 0,
        `haric_adet`             INT          NOT NULL DEFAULT 0,
        `beyan_adet`             INT          NULL DEFAULT NULL,
        `istek_id`               VARCHAR(64)  NULL DEFAULT NULL,
        `onceki_sayim_id`        INT          NULL DEFAULT NULL,
        `olusturan_user_id`      INT          NULL DEFAULT NULL,
        `onaylayan_user_id`      INT          NULL DEFAULT NULL,
        `onay_zamani`            DATETIME     NULL DEFAULT NULL,
        `geri_alan_user_id`      INT          NULL DEFAULT NULL,
        `geri_alma_zamani`       DATETIME     NULL DEFAULT NULL,
        `geri_alma_nedeni`       VARCHAR(500) NULL DEFAULT NULL,
        `created_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`             DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_ts_aktif` (`aktif_anahtar`),
        UNIQUE KEY `uq_ts_istek` (`istek_id`),
        INDEX `idx_ts_gun_depo` (`work_date`, `depo`),
        INDEX `idx_ts_cavus_gun` (`foreman_id`, `work_date`),
        INDEX `idx_ts_session` (`session_id`),
        CONSTRAINT `fk_ts_foreman` FOREIGN KEY (`foreman_id`)
            REFERENCES `foremen`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
        CONSTRAINT `fk_ts_session` FOREIGN KEY (`session_id`)
            REFERENCES `daily_work_sessions`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Sayımın kart listesi. Taslakta: 'kalan' (okutulan) / 'teyit' (dönmemiş kart
    // için 'bugün dağıtıldı' kararı). Onayda: havuzun TAMAMI anlık görüntü olarak
    // yazılır — 'dagitilan' / 'zaten_iceride' / 'haric' (+ sebep). period_id = oluşan dönem.
    $t['ters_sayim_kart'] = "CREATE TABLE IF NOT EXISTS `ters_sayim_kart` (
        `id`                        INT AUTO_INCREMENT PRIMARY KEY,
        `sayim_id`                  INT          NOT NULL,
        `worker_card_id`            INT          NOT NULL,
        `rol`                       VARCHAR(20)  NOT NULL,
        `teyitli`                   TINYINT(1)   NOT NULL DEFAULT 0,
        `haric_sebep`               VARCHAR(30)  NULL DEFAULT NULL,
        `worker_type_id_snapshot`   INT          NULL DEFAULT NULL,
        `worker_type_name_snapshot` VARCHAR(80)  NOT NULL DEFAULT '',
        `card_no_snapshot`          VARCHAR(30)  NOT NULL DEFAULT '',
        `period_id`                 INT          NULL DEFAULT NULL,
        `okutma_kaynak`             VARCHAR(20)  NULL DEFAULT NULL,
        `okutma_zamani`             DATETIME     NULL DEFAULT NULL,
        `user_id`                   INT          NULL DEFAULT NULL,
        `created_at`                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_tsk_sayim_kart` (`sayim_id`, `worker_card_id`),
        INDEX `idx_tsk_kart` (`worker_card_id`),
        INDEX `idx_tsk_period` (`period_id`),
        CONSTRAINT `fk_tsk_sayim` FOREIGN KEY (`sayim_id`)
            REFERENCES `ters_sayim`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
        CONSTRAINT `fk_tsk_card` FOREIGN KEY (`worker_card_id`)
            REFERENCES `worker_cards`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    return $t;
}

/** Modül kullanılabilir mi: 4 tablo + Faz 8A + Faz 8J şeması. Yalnız "hazır" sonucu önbelleklenir. */
function pdks_ters_sema_hazir(?PDO $pdo = null): bool
{
    static $var;
    $pdo = $pdo ?? db();
    $var ??= new WeakMap();
    if (isset($var[$pdo])) return true;
    foreach (array_keys(pdks_ters_tablolar()) as $ad) {
        if (!pdks_gunluk_tablo_var($pdo, $ad)) return false;
    }
    if (!pdks_gunluk_faz8a_sema_hazir($pdo) || !pdks_faz8j_sema_hazir($pdo)) return false;
    $var[$pdo] = true;
    return true;
}

/** İDEMPOTENT, yalnız CREATE TABLE. Önkoşul: Günlük İşçi + Faz 8A + Faz 8J. migrate.php'den çağrılır. */
function pdks_ters_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_gunluk_sema_hazir($pdo) || !pdks_gunluk_faz8a_sema_hazir($pdo) || !pdks_faz8j_sema_hazir($pdo)) {
        return [['tablo' => 'ters_*', 'durum' => 'atlandi',
                 'mesaj' => 'Önce Günlük İşçi, Faz 8A ve Faz 8J (puantaj düzeltme) şemalarını kurun.']];
    }
    $rapor = [];
    foreach (pdks_ters_tablolar() as $ad => $sql) {
        if (pdks_gunluk_tablo_var($pdo, $ad)) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            $pdo->exec($sql);
            $rapor[] = pdks_gunluk_tablo_var($pdo, $ad)
                ? ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']
                : ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'CREATE çalıştı ama tablo görünmüyor.'];
        } catch (PDOException $e) {
            error_log('[pdks_ters_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'İşlem tamamlanamadı. Teknik ayrıntılar sunucu günlüğüne kaydedildi.'];
        }
    }
    return $rapor;
}

// =========================================================
// ORTAK YARDIMCILAR
// =========================================================

function pdks_ters_mysql(PDO $pdo): bool { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
function pdks_ters_kilit_ek(PDO $pdo): string { return pdks_ters_mysql($pdo) ? ' FOR UPDATE' : ''; }
function pdks_ters_depo_esit(string $a, string $b): bool { return pdks_gunluk_depo_fold(trim($a)) === pdks_gunluk_depo_fold(trim($b)); }
function pdks_ters_aktif_depo(): string { return trim((string)(function_exists('active_depot') ? (active_depot() ?? '') : '')); }
function pdks_ters_metin($x): string { return is_scalar($x) ? trim((string)$x) : ''; }
function pdks_ters_red(string $kod, string $hata, array $ek = []): array { return ['ok' => false, 'kod' => $kod, 'hata' => $hata] + $ek; }

/** 'H:MM' / 'HH:MM' / 'HH.MM' → 'HH:MM' ya da null. */
function pdks_ters_saat(string $s): ?string
{
    $s = str_replace('.', ':', trim($s));
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/D', $s, $m)) return null;
    return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
}

/** İstek anahtarı (tekrar gönderim): 16-64 küçük onaltılık. */
function pdks_ters_istek_id($x): ?string
{
    $v = pdks_ters_metin($x);
    return preg_match('/^[a-f0-9]{16,64}$/D', $v) ? $v : null;
}

/** Çavuş + gün + depo için aktif sayım anahtarı (depo TR-duyarsız). */
function pdks_ters_anahtar(int $foremanId, string $workDate, string $depo): string
{
    return 'F' . $foremanId . ':' . $workDate . ':' . substr(hash('sha256', pdks_gunluk_depo_fold(trim($depo))), 0, 24);
}

/** UNIQUE / kilit kaynaklı eşzamanlılık hatası mı. */
function pdks_ters_eszamanli(Throwable $e): bool { return pdks_faz8j_eszamanli_hata($e); }

/** Transaction içi audit (JSON_THROW — yazılamazsa işlem geri alınır). */
function pdks_ters_audit(PDO $pdo, int $user, string $action, string $module, int $id, array $old, array $new): void
{
    pdks_faz8j_audit($pdo, $user, $action, $id, $old, $new, $module);
}

/** Ham okutma → kanonik UID → worker_cards satırı. */
function pdks_ters_uid_coz(PDO $pdo, string $ham, string $kaynak): array
{
    $ham = trim($ham);
    if ($ham === '') return pdks_ters_red('bos_uid', 'Kart okutulmadı.');
    if (!defined('PDKS_UID_KAYNAKLARI') || !in_array($kaynak, PDKS_UID_KAYNAKLARI, true)) {
        return pdks_ters_red('gecersiz_kaynak', 'UID kaynağı bildirilmeli.');
    }
    $kanonik = pdks_gunluk_uid_kanonik($ham, $kaynak);
    if ($kanonik === null) return pdks_ters_red('gecersiz_uid', 'Okunan UID geçersiz.');
    return ['ok' => true, 'kanonik' => $kanonik, 'kart' => pdks_gunluk_faz8a_kart_coz($kanonik, $pdo)];
}

/** Çavuş satırı (aktiflik dahil) ya da null. */
function pdks_ters_cavus(PDO $pdo, int $foremanId): ?array
{
    $st = $pdo->prepare('SELECT id, code, name, is_active FROM foremen WHERE id = ?');
    $st->execute([$foremanId]);
    return $st->fetch() ?: null;
}

/** Kart görünür durumu (havuz etiketiyle): Kullanımda / Kayıp / Bozuk / Kullanım dışı. */
function pdks_ters_kart_durum_adi(string $status, ?string $etiket): string
{
    if ($status === 'lost') return 'Kayıp';
    if ($status === 'disabled') return $etiket === 'bozuk' ? 'Bozuk' : 'Kullanım dışı';
    return 'Kullanımda';
}

/** Sayım satırı (çavuş adıyla) ya da null. */
function pdks_ters_sayim_getir(PDO $pdo, int $sayimId, bool $kilitle = false): ?array
{
    $st = $pdo->prepare('SELECT * FROM ters_sayim WHERE id = ?' . ($kilitle ? pdks_ters_kilit_ek($pdo) : ''));
    $st->execute([$sayimId]);
    $s = $st->fetch() ?: null;
    if ($s && !$kilitle) {
        $c = pdks_ters_cavus($pdo, (int)$s['foreman_id']);
        $s['foreman_name'] = $c ? (string)$c['name'] : '';
    }
    return $s;
}

// =========================================================
// SABİT GİRİŞ SAATİ (çavuş + depo, tarihli)
// =========================================================

/** Gün için geçerli ayar: en büyük valid_from ≤ gün (eşitlikte son kayıt). */
function pdks_ters_giris_saati(PDO $pdo, int $foremanId, string $depo, string $workDate): ?array
{
    $st = $pdo->prepare('SELECT * FROM ters_cavus_ayar WHERE foreman_id = ? AND valid_from <= ? ORDER BY valid_from DESC, id DESC');
    $st->execute([$foremanId, $workDate]);
    foreach ($st->fetchAll() as $r) {
        if (pdks_ters_depo_esit((string)$r['depo'], $depo)) return $r;
    }
    return null;
}

/** Çavuş + depo ayar geçmişi (yeni → eski). */
function pdks_ters_giris_saati_gecmis(PDO $pdo, int $foremanId, string $depo): array
{
    try {
        $st = $pdo->prepare('SELECT a.*, u.display_name AS kullanici FROM ters_cavus_ayar a LEFT JOIN users u ON u.id = a.created_by_user_id WHERE a.foreman_id = ? ORDER BY a.valid_from DESC, a.id DESC');
        $st->execute([$foremanId]); $rows = $st->fetchAll();
    } catch (PDOException $e) {   // users tablosu/kolonu yoksa adsız
        $st = $pdo->prepare('SELECT a.* FROM ters_cavus_ayar a WHERE a.foreman_id = ? ORDER BY a.valid_from DESC, a.id DESC');
        $st->execute([$foremanId]); $rows = $st->fetchAll();
    }
    return array_values(array_filter($rows, fn($r) => pdks_ters_depo_esit((string)$r['depo'], $depo)));
}

/**
 * Sabit giriş saati kaydı (yeni satır — geçmiş değişmez). Geçerlilik bugünden
 * önce OLAMAZ (geriye dönük değil; onaylı sayımlar kendi saatini zaten dondurur).
 * Yetki ÇAĞIRANDA (attendance.worker_cards). Depo = aktif depo.
 */
function pdks_ters_giris_saati_kaydet(int $foremanId, string $saat, string $validFrom, string $reason, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    $depo = pdks_ters_aktif_depo();
    if ($depo === '') return pdks_ters_red('depo_yok', 'Önce bir depo seçmelisiniz.');
    $c = pdks_ters_cavus($pdo, $foremanId);
    if (!$c) return pdks_ters_red('cavus_yok', 'Çavuş bulunamadı.');
    $s = pdks_ters_saat($saat);
    if ($s === null) return pdks_ters_red('saat_gecersiz', 'Giriş saatini SS:DD biçiminde girin (ör. 08:00).');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom);
    if (!$d || $d->format('Y-m-d') !== $validFrom) return pdks_ters_red('tarih_gecersiz', 'Geçerlilik başlangıcı geçerli bir tarih olmalı.');
    if ($validFrom < date('Y-m-d')) return pdks_ters_red('gecmis_tarih', 'Geçerlilik başlangıcı bugünden önce olamaz (geçmiş sayımlar kendi saatini korur).');
    $reason = trim($reason);
    if (mb_strlen($reason) > 255) return pdks_ters_red('gerekce', 'Açıklama en fazla 255 karakter olabilir.');
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO ters_cavus_ayar (foreman_id, depo, giris_saati, valid_from, reason, created_by_user_id) VALUES (?,?,?,?,?,?)')
            ->execute([$foremanId, $depo, $s, $validFrom, $reason !== '' ? $reason : null, $user]);
        $id = (int)$pdo->lastInsertId();
        pdks_ters_audit($pdo, $user, 'ters_giris_saati', 'ters_cavus_ayar', $id, [],
            ['foreman_id' => $foremanId, 'depo' => $depo, 'giris_saati' => $s, 'valid_from' => $validFrom, 'reason' => $reason]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[pdks_ters_giris_saati_kaydet] ' . $e->getMessage());
        return pdks_ters_red('yazma_hatasi', 'Giriş saati kaydedilemedi. Lütfen tekrar deneyin.');
    }
    return ['ok' => true, 'id' => $id, 'giris_saati' => $s, 'valid_from' => $validFrom];
}

// =========================================================
// KART HAVUZU (çavuş + depo, kalıcı)
// =========================================================

/** Aktif havuz üyeleri (kart + tip bilgisiyle), kart no doğal sırada. */
function pdks_ters_havuz_uyeleri(PDO $pdo, int $foremanId, string $depo): array
{
    $st = $pdo->prepare(
        "SELECT h.id AS uye_id, h.foreman_id, h.depo, h.worker_card_id, h.worker_type_id, h.valid_from,
                h.durum_etiketi, h.durum_notu, h.durum_zamani,
                w.card_no, w.status, w.canonical_uid, t.name AS tip_adi, t.code AS tip_kodu
           FROM ters_havuz_kart h
           JOIN worker_cards w ON w.id = h.worker_card_id
           JOIN worker_types t ON t.id = h.worker_type_id
          WHERE h.foreman_id = ? AND h.aktif_kart_id IS NOT NULL"
    );
    $st->execute([$foremanId]);
    $rows = array_values(array_filter($st->fetchAll(), fn($r) => pdks_ters_depo_esit((string)$r['depo'], $depo)));
    usort($rows, fn($a, $b) => strnatcasecmp((string)$a['card_no'], (string)$b['card_no']));
    return $rows;
}

/** Havuz sayaçları: toplam envanter · aktif (kullanılabilir) · kayıp · bozuk · kullanım dışı · tip bazında aktif. */
function pdks_ters_havuz_ozet(array $uyeler): array
{
    $o = ['toplam' => count($uyeler), 'aktif' => 0, 'kayip' => 0, 'bozuk' => 0, 'kullanim_disi' => 0, 'tipler' => []];
    foreach ($uyeler as $u) {
        if ($u['status'] === 'lost') { $o['kayip']++; continue; }
        if ($u['status'] === 'disabled') { ($u['durum_etiketi'] === 'bozuk') ? $o['bozuk']++ : $o['kullanim_disi']++; continue; }
        $o['aktif']++;
        $o['tipler'][(string)$u['tip_adi']] = ($o['tipler'][(string)$u['tip_adi']] ?? 0) + 1;
    }
    return $o;
}

/** Aktif depoda havuzu olan çavuşlar (ada göre) + havuz özeti. */
function pdks_ters_havuzlu_cavuslar(PDO $pdo, string $depo): array
{
    $st = $pdo->query(
        "SELECT DISTINCT h.foreman_id, h.depo, f.name, f.code, f.is_active
           FROM ters_havuz_kart h JOIN foremen f ON f.id = h.foreman_id
          WHERE h.aktif_kart_id IS NOT NULL"
    );
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if (!pdks_ters_depo_esit((string)$r['depo'], $depo) || isset($out[(int)$r['foreman_id']])) continue;
        $out[(int)$r['foreman_id']] = ['foreman_id' => (int)$r['foreman_id'], 'name' => (string)$r['name'],
                                       'code' => (string)$r['code'], 'is_active' => (int)$r['is_active']];
    }
    uasort($out, fn($a, $b) => strcoll($a['name'], $b['name']));
    foreach ($out as $id => &$c) $c['havuz'] = pdks_ters_havuz_ozet(pdks_ters_havuz_uyeleri($pdo, $id, $depo));
    unset($c);
    return array_values($out);
}

/** Aktif çavuş listesi (havuz/ayar ekranının seçim kutusu). */
function pdks_ters_cavus_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT id, code, name, is_active FROM foremen WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
}

/** Bir havuz üyeliğini kapatır (transaction İÇİNDE, kart kilitli). */
function pdks_ters_uyelik_kapat(PDO $pdo, int $uyeId, int $kartId, int $user, string $reason): void
{
    $pdo->prepare('UPDATE ters_havuz_kart SET valid_to = ?, aktif_kart_id = NULL, ended_by_user_id = ?, end_reason = ? WHERE id = ? AND aktif_kart_id = ?')
        ->execute([date('Y-m-d H:i:s'), $user, mb_substr($reason, 0, 255), $uyeId, $kartId]);
}

/** Yeni havuz üyeliği (transaction İÇİNDE, kart kilitli). */
function pdks_ters_uyelik_ac(PDO $pdo, int $foremanId, string $depo, int $kartId, int $tipId, int $user): int
{
    $pdo->prepare('INSERT INTO ters_havuz_kart (foreman_id, depo, worker_card_id, worker_type_id, aktif_kart_id, valid_from, created_by_user_id) VALUES (?,?,?,?,?,?,?)')
        ->execute([$foremanId, $depo, $kartId, $tipId, $kartId, date('Y-m-d H:i:s'), $user]);
    return (int)$pdo->lastInsertId();
}

/**
 * Kart bu çavuşun havuzuna girebilir mi (kart kilidi ALTINDA). Hata metni ya da null.
 * Kurallar: kartsız sanal kart değil · kayıp/devre dışı değil · Kart Havuzu'nda BAŞKA
 * çavuşa/depoya tanımlı değil (aynı çavuşa tanımlıysa tip tanımla aynı olmalı).
 */
function pdks_ters_havuz_kart_engeli(PDO $pdo, array $kart, int $foremanId, string $depo, int $tipId): ?string
{
    if ((string)($kart['enrolled_source'] ?? '') === 'kartsiz') return 'Kartsız mesainin sanal kartı havuza eklenemez.';
    if ((string)$kart['status'] === 'lost') return 'Kart KAYIP işaretli (' . $kart['card_no'] . ') — bulunduysa önce Kullanıma Al.';
    if ((string)$kart['status'] === 'disabled') return 'Kart DEVRE DIŞI (' . $kart['card_no'] . ') — önce Kullanıma Al.';
    $t = pdks_gunluk_kart_tanim_aktif($pdo, (int)$kart['id']);
    if ($t) {
        if ((int)$t['foreman_id'] !== $foremanId || !pdks_ters_depo_esit((string)$t['depo'], $depo)) {
            return 'Kart Kart Havuzu\'nda ' . pdks_gunluk_kart_tanim_kime($t) . ' tanımlı (' . $t['depo'] . ') — önce Tanımı Kaldır.';
        }
        if ((int)$t['worker_type_id'] !== $tipId) {
            return 'Kart bu çavuşa ' . $t['tip_adi'] . ' olarak tanımlı — havuza aynı tiple eklenmeli.';
        }
    }
    return null;
}

/**
 * Okutulan kartı çavuşun havuzuna ekler (her okutma ATOMİK, idempotent).
 * Bilinmeyen UID → havuza yeni kart (mevcut pdks_gunluk_kart_olustur, kart no sıradan).
 * Aynı çavuş + aynı tip → 'zaten' (sayılmaz). Aynı çavuş farklı tip → tip güncellenir
 * (eski üyelik kapanır, geçmiş korunur). Başka çavuş/depo → RED (önce çıkar / Aktar).
 * Yetki ÇAĞIRANDA (attendance.worker_cards). Depo = aktif depo.
 */
function pdks_ters_havuz_ekle(string $ham, string $kaynak, int $foremanId, int $tipId, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    if ($pdo->inTransaction()) return pdks_ters_red('ic_ice_islem', 'Bu işlem başka bir işlemin içinde çalıştırılamaz.');
    $depo = pdks_ters_aktif_depo();
    if ($depo === '') return pdks_ters_red('depo_yok', 'Önce bir depo seçmelisiniz.');
    $c = pdks_ters_cavus($pdo, $foremanId);
    if (!$c) return pdks_ters_red('cavus_yok', 'Çavuş seçin.');
    if (!(int)$c['is_active']) return pdks_ters_red('cavus_pasif', 'Pasif çavuşun havuzuna kart eklenemez.');
    $tip = pdks_gunluk_desteklenen_tip_coz($tipId, $pdo);
    if (!$tip) return pdks_ters_red('tip_desteklenmiyor', 'İşçi tipi seçin (Kadın / Erkek / Rampacı).');

    $u = pdks_ters_uid_coz($pdo, $ham, $kaynak);
    if (!$u['ok']) return $u;
    $kart = $u['kart'];
    $yeniKart = false;
    if ($kart === null && count(pdks_ters_havuz_uyeleri($pdo, $foremanId, $depo)) >= PDKS_TERS_HAVUZ_LIMIT) {
        return pdks_ters_red('limit', 'Bir çavuşun havuzunda en çok ' . PDKS_TERS_HAVUZ_LIMIT . ' kart olabilir.');   // kart yaratılmadan önce
    }
    if ($kart === null) {
        $engel = pdks_gunluk_kalici_kart_engeli($ham, $kaynak, $pdo);
        if ($engel !== null) return ['ok' => false] + $engel;
        // Kart no sıradan; eşzamanlı iki ekleme aynı numarayı alırsa bir kez yeniden dener.
        for ($deneme = 0; $deneme < 3 && $kart === null; $deneme++) {
            $o = pdks_gunluk_kart_olustur(['card_no' => pdks_gunluk_sonraki_kart_no(0, $pdo), 'ham_uid' => $ham, 'kaynak' => $kaynak,
                                           'notes' => 'Giriş Tanımlı havuzuna eklendi'], $user, $pdo);
            $kart = pdks_gunluk_faz8a_kart_coz((string)$u['kanonik'], $pdo);
            if ($kart === null && (string)($o['kod'] ?? '') !== 'kart_no_kullanimda') {
                return pdks_ters_red((string)($o['kod'] ?? 'kart_eklenemedi'), (string)($o['hata'] ?? 'Kart havuza eklenemedi.'));
            }
            $yeniKart = $kart !== null && !empty($o['ok']);
        }
        if ($kart === null) return pdks_ters_red('kart_eklenemedi', 'Kart eklenemedi (eşzamanlı işlem). Tekrar okutun.');
    }
    $kartId = (int)$kart['id'];
    $sonuc = 'eklendi'; $eskiTip = null;
    try {
        $pdo->beginTransaction();
        pdks_gunluk_faz8a_kart_kilitle($pdo, $kartId);
        $st = $pdo->prepare('SELECT * FROM worker_cards WHERE id = ?'); $st->execute([$kartId]); $kart = $st->fetch();
        $mevcut = pdks_gunluk_ters_havuz_aktif($pdo, $kartId);
        if ($mevcut) {
            if ((int)$mevcut['foreman_id'] !== $foremanId || !pdks_ters_depo_esit((string)$mevcut['depo'], $depo)) {
                $pdo->rollBack();
                return pdks_ters_red('baska_havuzda', 'Kart ' . $kart['card_no'] . ' zaten ' . $mevcut['foreman_name']
                    . ' çavuşunun havuzunda (' . $mevcut['depo'] . ') — önce oradan çıkarın ya da Aktar kullanın.', ['card_no' => (string)$kart['card_no']]);
            }
            if ((int)$mevcut['worker_type_id'] === (int)$tip['id']) {
                $pdo->rollBack();
                return ['ok' => true, 'sonuc' => 'zaten', 'card_no' => (string)$kart['card_no'], 'tip_adi' => (string)$tip['name'],
                        'ozet' => pdks_ters_havuz_ozet(pdks_ters_havuz_uyeleri($pdo, $foremanId, $depo))];
            }
        }
        if ($e = pdks_ters_havuz_kart_engeli($pdo, $kart, $foremanId, $depo, (int)$tip['id'])) {
            $pdo->rollBack();
            return pdks_ters_red('kart_uygun_degil', $e, ['card_no' => (string)$kart['card_no']]);
        }
        if (!$mevcut && count(pdks_ters_havuz_uyeleri($pdo, $foremanId, $depo)) >= PDKS_TERS_HAVUZ_LIMIT) {
            $pdo->rollBack();
            return pdks_ters_red('limit', 'Bir çavuşun havuzunda en çok ' . PDKS_TERS_HAVUZ_LIMIT . ' kart olabilir.');
        }
        if ($mevcut) {
            $eskiTip = (int)$mevcut['worker_type_id'];
            pdks_ters_uyelik_kapat($pdo, (int)$mevcut['id'], $kartId, $user, 'Tip değiştirildi');
            $sonuc = 'tip_guncellendi';
        }
        $uyeId = pdks_ters_uyelik_ac($pdo, $foremanId, $depo, $kartId, (int)$tip['id'], $user);
        pdks_ters_audit($pdo, $user, 'ters_havuz_ekle', 'ters_havuz_kart', $uyeId, $eskiTip !== null ? ['worker_type_id' => $eskiTip] : [],
            ['foreman_id' => $foremanId, 'depo' => $depo, 'worker_card_id' => $kartId, 'card_no' => (string)$kart['card_no'],
             'worker_type_id' => (int)$tip['id'], 'yeni_kart' => $yeniKart, 'sonuc' => $sonuc]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (pdks_ters_eszamanli($e)) return pdks_ters_red('eszamanli', PDKS_TERS_ESZAMANLI);
        error_log('[pdks_ters_havuz_ekle] ' . $e->getMessage());
        return pdks_ters_red('yazma_hatasi', 'Kart havuza eklenemedi. Lütfen tekrar deneyin.');
    }
    $uyari = null;
    if ($acik = pdks_gunluk_faz8a_kart_acik_donemi($pdo, $kartId)) {
        $uyari = 'Kart şu an ' . $acik['foreman_name'] . ' mesaisinde içeride — çıkışı Hızlı Çıkış ile yapılır.';
    }
    return ['ok' => true, 'sonuc' => $sonuc, 'yeni_kart' => $yeniKart, 'card_no' => (string)$kart['card_no'],
            'tip_adi' => (string)$tip['name'], 'uyari' => $uyari,
            'ozet' => pdks_ters_havuz_ozet(pdks_ters_havuz_uyeleri($pdo, $foremanId, $depo))];
}

/** Kartın bu çavuş + aktif depodaki üyeliğini kilit altında doğrular (transaction İÇİNDE). */
function pdks_ters_uyelik_dogrula(PDO $pdo, int $kartId, int $foremanId, string $depo): ?array
{
    pdks_gunluk_faz8a_kart_kilitle($pdo, $kartId);
    $m = pdks_gunluk_ters_havuz_aktif($pdo, $kartId);
    if (!$m || (int)$m['foreman_id'] !== $foremanId || !pdks_ters_depo_esit((string)$m['depo'], $depo)) return null;
    return $m;
}

/** Kartı havuzdan çıkarır (gerekçe zorunlu; üyelik satırı SİLİNMEZ). Geçmiş sayımlar anlık görüntüsünü korur. */
function pdks_ters_havuz_cikar(int $kartId, int $foremanId, string $reason, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    $depo = pdks_ters_aktif_depo();
    if ($depo === '') return pdks_ters_red('depo_yok', 'Önce bir depo seçmelisiniz.');
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 255) return pdks_ters_red('gerekce', 'Gerekçe zorunludur (en fazla 255 karakter).');
    try {
        $pdo->beginTransaction();
        $m = pdks_ters_uyelik_dogrula($pdo, $kartId, $foremanId, $depo);
        if (!$m) { $pdo->rollBack(); return pdks_ters_red('uye_degil', 'Kart bu çavuşun havuzunda değil.'); }
        pdks_ters_uyelik_kapat($pdo, (int)$m['id'], $kartId, $user, $reason);
        pdks_ters_audit($pdo, $user, 'ters_havuz_cikar', 'ters_havuz_kart', (int)$m['id'],
            ['foreman_id' => $foremanId, 'worker_card_id' => $kartId, 'worker_type_id' => (int)$m['worker_type_id']], ['reason' => $reason]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[pdks_ters_havuz_cikar] ' . $e->getMessage());
        return pdks_ters_red('yazma_hatasi', 'Kart çıkarılamadı. Lütfen tekrar deneyin.');
    }
    return ['ok' => true];
}

/**
 * Kartı aynı depoda başka bir çavuşun havuzuna aktarır (tip korunur). Tek transaction;
 * hedef çavuş için tanım kuralı yeniden denetlenir. Gerekçe zorunlu.
 */
function pdks_ters_havuz_aktar(int $kartId, int $foremanId, int $hedefForemanId, string $reason, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    $depo = pdks_ters_aktif_depo();
    if ($depo === '') return pdks_ters_red('depo_yok', 'Önce bir depo seçmelisiniz.');
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 255) return pdks_ters_red('gerekce', 'Gerekçe zorunludur (en fazla 255 karakter).');
    if ($hedefForemanId === $foremanId) return pdks_ters_red('ayni_cavus', 'Hedef çavuş farklı olmalı.');
    $h = pdks_ters_cavus($pdo, $hedefForemanId);
    if (!$h || !(int)$h['is_active']) return pdks_ters_red('cavus_yok', 'Hedef çavuş bulunamadı ya da pasif.');
    try {
        $pdo->beginTransaction();
        $m = pdks_ters_uyelik_dogrula($pdo, $kartId, $foremanId, $depo);
        if (!$m) { $pdo->rollBack(); return pdks_ters_red('uye_degil', 'Kart bu çavuşun havuzunda değil.'); }
        $st = $pdo->prepare('SELECT * FROM worker_cards WHERE id = ?'); $st->execute([$kartId]); $kart = $st->fetch();
        $t = pdks_gunluk_kart_tanim_aktif($pdo, $kartId);
        if ($t && ((int)$t['foreman_id'] !== $hedefForemanId || !pdks_ters_depo_esit((string)$t['depo'], $depo) || (int)$t['worker_type_id'] !== (int)$m['worker_type_id'])) {
            $pdo->rollBack();
            return pdks_ters_red('tanim_cakisma', 'Kart Kart Havuzu\'nda ' . pdks_gunluk_kart_tanim_kime($t) . ' tanımlı — önce Tanımı Kaldır.');
        }
        if (count(pdks_ters_havuz_uyeleri($pdo, $hedefForemanId, $depo)) >= PDKS_TERS_HAVUZ_LIMIT) {
            $pdo->rollBack();
            return pdks_ters_red('limit', 'Hedef çavuşun havuzu dolu (en çok ' . PDKS_TERS_HAVUZ_LIMIT . ' kart).');
        }
        pdks_ters_uyelik_kapat($pdo, (int)$m['id'], $kartId, $user, 'Aktarıldı: ' . $reason);
        $yeni = pdks_ters_uyelik_ac($pdo, $hedefForemanId, $depo, $kartId, (int)$m['worker_type_id'], $user);
        pdks_ters_audit($pdo, $user, 'ters_havuz_aktar', 'ters_havuz_kart', $yeni,
            ['foreman_id' => $foremanId, 'uye_id' => (int)$m['id']],
            ['foreman_id' => $hedefForemanId, 'worker_card_id' => $kartId, 'card_no' => (string)$kart['card_no'], 'reason' => $reason]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (pdks_ters_eszamanli($e)) return pdks_ters_red('eszamanli', PDKS_TERS_ESZAMANLI);
        error_log('[pdks_ters_havuz_aktar] ' . $e->getMessage());
        return pdks_ters_red('yazma_hatasi', 'Kart aktarılamadı. Lütfen tekrar deneyin.');
    }
    return ['ok' => true, 'hedef' => (string)$h['name']];
}

/**
 * Havuzu sıfırlar: çavuşun aktif depodaki TÜM üyelikleri kapanır (satırlar ve geçmiş
 * sayımlar KORUNUR). Yeniden oluşturma = sonra kartları yeniden okutmak.
 */
function pdks_ters_havuz_sifirla(int $foremanId, string $reason, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    if ($pdo->inTransaction()) return pdks_ters_red('ic_ice_islem', 'Bu işlem başka bir işlemin içinde çalıştırılamaz.');
    $depo = pdks_ters_aktif_depo();
    if ($depo === '') return pdks_ters_red('depo_yok', 'Önce bir depo seçmelisiniz.');
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 255) return pdks_ters_red('gerekce', 'Gerekçe zorunludur (en fazla 255 karakter).');
    $uyeler = pdks_ters_havuz_uyeleri($pdo, $foremanId, $depo);
    if (!$uyeler) return pdks_ters_red('bos', 'Havuz zaten boş.');
    $ids = array_map(fn($u) => (int)$u['worker_card_id'], $uyeler);
    sort($ids, SORT_NUMERIC);
    $kapanan = [];
    try {
        $pdo->beginTransaction();
        foreach ($ids as $kid) {
            $m = pdks_ters_uyelik_dogrula($pdo, $kid, $foremanId, $depo);
            if (!$m) continue;   // arada çıkarıldıysa atla
            pdks_ters_uyelik_kapat($pdo, (int)$m['id'], $kid, $user, 'Havuz sıfırlandı: ' . $reason);
            $kapanan[] = $kid;
        }
        pdks_ters_audit($pdo, $user, 'ters_havuz_sifirla', 'ters_havuz_kart', 0, [],
            ['foreman_id' => $foremanId, 'depo' => $depo, 'kart_ids' => $kapanan, 'adet' => count($kapanan), 'reason' => $reason]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[pdks_ters_havuz_sifirla] ' . $e->getMessage());
        return pdks_ters_red('yazma_hatasi', 'Havuz sıfırlanamadı. Lütfen tekrar deneyin.');
    }
    return ['ok' => true, 'adet' => count($kapanan)];
}

/**
 * Havuzdaki kartın durumu: kayıp / bozuk / kullanım dışı / kullanıma al. Kartın
 * GLOBAL durumu mevcut pdks_gunluk_kart_durum_degistir() ile değişir (kayıp/devre
 * dışı kart kiosk girişine de kapanır); bozuk/kullanım dışı ayrımı havuz etiketinde
 * tutulur. Kart içerideyse ENGEL DEĞİL (çıkış her durumda yapılabilir).
 * Yetki ÇAĞIRANDA: havuz ekranı attendance.worker_cards; sabah sayımı yalnız 'kayip'
 * (dönmemiş kart kararı, attendance.daily_scan).
 */
function pdks_ters_kart_durum(int $kartId, int $foremanId, string $islem, string $not, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    if ($pdo->inTransaction()) return pdks_ters_red('ic_ice_islem', 'Bu işlem başka bir işlemin içinde çalıştırılamaz.');
    $islemler = pdks_ters_kart_islemleri();
    if (!isset($islemler[$islem])) return pdks_ters_red('gecersiz', 'Geçersiz işlem.');
    $depo = pdks_ters_aktif_depo();
    if ($depo === '') return pdks_ters_red('depo_yok', 'Önce bir depo seçmelisiniz.');
    $not = trim($not);
    if (mb_strlen($not) > 255) return pdks_ters_red('gerekce', 'Not en fazla 255 karakter olabilir.');
    $hedef = $islemler[$islem];
    try {
        $pdo->beginTransaction();
        $m = pdks_ters_uyelik_dogrula($pdo, $kartId, $foremanId, $depo);
        if (!$m) { $pdo->rollBack(); return pdks_ters_red('uye_degil', 'Kart bu çavuşun havuzunda değil.'); }
        $st = $pdo->prepare('SELECT status FROM worker_cards WHERE id = ?'); $st->execute([$kartId]);
        $eski = (string)$st->fetchColumn();
        $r = pdks_gunluk_kart_durum_degistir($kartId, $hedef['status'], $user, $pdo);
        if (empty($r['ok'])) { $pdo->rollBack(); return pdks_ters_red('durum_hatasi', (string)($r['hata'] ?? 'Durum değiştirilemedi.')); }
        $pdo->prepare('UPDATE ters_havuz_kart SET durum_etiketi = ?, durum_notu = ?, durum_zamani = ? WHERE id = ?')
            ->execute([$hedef['etiket'], $not !== '' ? $not : null, date('Y-m-d H:i:s'), (int)$m['id']]);
        pdks_ters_audit($pdo, $user, 'ters_kart_durum', 'ters_havuz_kart', (int)$m['id'],
            ['status' => $eski, 'durum_etiketi' => $m['durum_etiketi']],
            ['worker_card_id' => $kartId, 'status' => $hedef['status'], 'durum_etiketi' => $hedef['etiket'], 'islem' => $islem, 'not' => $not]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (pdks_ters_eszamanli($e)) return pdks_ters_red('eszamanli', PDKS_TERS_ESZAMANLI);
        error_log('[pdks_ters_kart_durum] ' . $e->getMessage());
        return pdks_ters_red('yazma_hatasi', 'Kart durumu değiştirilemedi. Lütfen tekrar deneyin.');
    }
    return ['ok' => true, 'durum' => $hedef['ad']];
}

/** Havuz üyelik geçmişi (eklenen/çıkarılan, yeni → eski). */
function pdks_ters_havuz_gecmis(PDO $pdo, int $foremanId, string $depo, int $limit = 200): array
{
    $st = $pdo->prepare(
        "SELECT h.*, w.card_no, t.name AS tip_adi
           FROM ters_havuz_kart h JOIN worker_cards w ON w.id = h.worker_card_id JOIN worker_types t ON t.id = h.worker_type_id
          WHERE h.foreman_id = ? ORDER BY h.id DESC LIMIT " . max(1, min(1000, $limit))
    );
    $st->execute([$foremanId]);
    return array_values(array_filter($st->fetchAll(), fn($r) => pdks_ters_depo_esit((string)$r['depo'], $depo)));
}

/** Kartın ters sayım hareketleri (yeni → eski): gün, çavuş, rol, giriş/çıkış. */
function pdks_ters_kart_hareketleri(PDO $pdo, int $kartId, int $limit = 30): array
{
    $st = $pdo->prepare(
        "SELECT k.rol, k.teyitli, k.haric_sebep, k.period_id, k.okutma_zamani, s.id AS sayim_id, s.work_date, s.durum, s.depo,
                f.name AS foreman_name, p.entry_time, p.exit_time, p.is_voided
           FROM ters_sayim_kart k
           JOIN ters_sayim s ON s.id = k.sayim_id
           JOIN foremen f ON f.id = s.foreman_id
           LEFT JOIN daily_worker_work_periods p ON p.id = k.period_id
          WHERE k.worker_card_id = ? ORDER BY s.work_date DESC, k.id DESC LIMIT " . max(1, min(200, $limit))
    );
    $st->execute([$kartId]);
    return $st->fetchAll();
}

// =========================================================
// SABAH SAYIMI — taslak → okut → önizle → onayla → (geri al)
// =========================================================

/** Bugünkü aktif (taslak/onaylı) sayım ya da null. */
function pdks_ters_sayim_aktif(PDO $pdo, int $foremanId, string $depo, string $workDate): ?array
{
    $st = $pdo->prepare('SELECT id FROM ters_sayim WHERE aktif_anahtar = ?');
    $st->execute([pdks_ters_anahtar($foremanId, $workDate, $depo)]);
    $id = (int)$st->fetchColumn();
    return $id ? pdks_ters_sayim_getir($pdo, $id) : null;
}

/** Sayımın taslak satırları: [kart_id => satır]. */
function pdks_ters_sayim_satirlari(PDO $pdo, int $sayimId): array
{
    $st = $pdo->prepare('SELECT * FROM ters_sayim_kart WHERE sayim_id = ? ORDER BY id ASC');
    $st->execute([$sayimId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['worker_card_id']] = $r;
    return $out;
}

/**
 * Bugün için sayım açar ya da mevcut aktif sayımı döner (iki operatör aynı anda
 * açarsa UNIQUE anahtar tek satırı garanti eder). Havuz boşsa açılmaz.
 * Yetki ÇAĞIRANDA (attendance.daily_scan).
 */
function pdks_ters_sayim_ac(int $foremanId, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    $depo = pdks_ters_aktif_depo();
    if ($depo === '') return pdks_ters_red('depo_yok', 'Önce bir depo seçmelisiniz.');
    $bugun = date('Y-m-d');
    if ($s = pdks_ters_sayim_aktif($pdo, $foremanId, $depo, $bugun)) return ['ok' => true, 'sayim' => $s, 'yeni' => false];
    $c = pdks_ters_cavus($pdo, $foremanId);
    if (!$c) return pdks_ters_red('cavus_yok', 'Çavuş bulunamadı.');
    if (!(int)$c['is_active']) return pdks_ters_red('cavus_pasif', 'Bu çavuş pasif — önce aktifleştirin.');
    if (!pdks_ters_havuz_uyeleri($pdo, $foremanId, $depo)) {
        return pdks_ters_red('havuz_bos', 'Bu çavuşun Giriş Tanımlı havuzu boş — önce Kart Havuzu ekranından kartları tanımlayın.');
    }
    $ayar = pdks_ters_giris_saati($pdo, $foremanId, $depo, $bugun);
    try {
        $pdo->prepare('INSERT INTO ters_sayim (foreman_id, depo, work_date, durum, aktif_anahtar, varsayilan_giris_saati, olusturan_user_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$foremanId, $depo, $bugun, 'taslak', pdks_ters_anahtar($foremanId, $bugun, $depo), $ayar ? (string)$ayar['giris_saati'] : null, $user]);
        $id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if (pdks_ters_eszamanli($e) && ($s = pdks_ters_sayim_aktif($pdo, $foremanId, $depo, $bugun))) return ['ok' => true, 'sayim' => $s, 'yeni' => false];
        error_log('[pdks_ters_sayim_ac] ' . $e->getMessage());
        return pdks_ters_red('yazma_hatasi', 'Sayım başlatılamadı. Lütfen tekrar deneyin.');
    }
    if (function_exists('audit_log_event')) audit_log_event('ters_sayim_ac', 'ters_sayim', $id, null, ['foreman_id' => $foremanId, 'depo' => $depo, 'work_date' => $bugun]);
    return ['ok' => true, 'sayim' => pdks_ters_sayim_getir($pdo, $id), 'yeni' => true];
}

/** Taslak üzerinde işlem öncesi ortak kapı (sayım satırı KİLİTLİ okunur — transaction İÇİNDE). */
function pdks_ters_taslak_kapi(PDO $pdo, int $sayimId): array
{
    $s = pdks_ters_sayim_getir($pdo, $sayimId, true);
    if (!$s) return pdks_ters_red('sayim_yok', 'Sayım bulunamadı.');
    if (!pdks_ters_depo_esit((string)$s['depo'], pdks_ters_aktif_depo())) return pdks_ters_red('yanlis_depo', 'Bu sayım aktif depoya ait değil.');
    if ((string)$s['durum'] !== 'taslak') {
        return pdks_ters_red('sayim_kapali', pdks_ters_sayim_onayli_mi($s)
            ? 'Bu sayım onaylandı — değişiklik için önce "Sayımı Geri Al".' : 'Bu sayım geri alınmış.');
    }
    if ((string)$s['work_date'] !== date('Y-m-d')) return pdks_ters_red('eski_gun', 'Bu sayım geçmiş bir güne ait — bugün için yeni sayım başlatın.');
    return ['ok' => true, 'sayim' => $s];
}

/** Sayım ekranının canlı sayaçları (salt okunur, ucuz): havuz aktif · kalan · dağıtılacak. */
function pdks_ters_sayim_sayac(PDO $pdo, array $sayim): array
{
    $uyeler = pdks_ters_havuz_uyeleri($pdo, (int)$sayim['foreman_id'], (string)$sayim['depo']);
    $oz = pdks_ters_havuz_ozet($uyeler);
    $satir = pdks_ters_sayim_satirlari($pdo, (int)$sayim['id']);
    $aktifIds = [];
    foreach ($uyeler as $u) if ($u['status'] === 'available') $aktifIds[(int)$u['worker_card_id']] = true;
    $kalan = 0; $teyit = 0;
    foreach ($satir as $kid => $r) {
        if ($r['rol'] === 'kalan' && isset($aktifIds[$kid])) $kalan++;
        if ($r['rol'] === 'teyit') $teyit++;
    }
    return ['havuz_toplam' => $oz['toplam'], 'havuz_aktif' => $oz['aktif'], 'haric' => $oz['toplam'] - $oz['aktif'],
            'kalan' => $kalan, 'dagitilacak' => max(0, $oz['aktif'] - $kalan), 'teyit' => $teyit];
}

/**
 * Elde kalan kartı okutur. Aynı kart ikinci kez → sayılmaz ('mukerrer'). Başka çavuşun
 * kartı / havuz dışı / kayıp kart → RED (sessizce sayılmaz). Sayım satırı kilitlidir:
 * onaylanmış sayıma okutma yazılamaz. Yetki ÇAĞIRANDA (attendance.daily_scan).
 */
function pdks_ters_sayim_okut(int $sayimId, string $ham, string $kaynak, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    if ($pdo->inTransaction()) return pdks_ters_red('ic_ice_islem', 'Bu işlem başka bir işlemin içinde çalıştırılamaz.');
    $u = pdks_ters_uid_coz($pdo, $ham, $kaynak);
    if (!$u['ok']) return $u;
    if ($u['kart'] === null) return pdks_ters_red('kart_tanimsiz', 'Tanımsız kart — hiçbir havuzda kayıtlı değil.');
    $kart = $u['kart']; $kartId = (int)$kart['id'];
    $mukerrer = false; $m = null;
    try {
        $pdo->beginTransaction();
        $k = pdks_ters_taslak_kapi($pdo, $sayimId);
        if (!$k['ok']) { $pdo->rollBack(); return $k; }
        $s = $k['sayim'];
        $m = pdks_gunluk_ters_havuz_aktif($pdo, $kartId);
        if (!$m || (int)$m['foreman_id'] !== (int)$s['foreman_id'] || !pdks_ters_depo_esit((string)$m['depo'], (string)$s['depo'])) {
            $pdo->rollBack();
            return $m
                ? pdks_ters_red('baska_cavus', 'Kart ' . $kart['card_no'] . ' ' . $m['foreman_name'] . ' çavuşunun havuzunda — bu çavuşun kalan kartı sayılmaz.', ['card_no' => (string)$kart['card_no']])
                : pdks_ters_red('havuz_disi', 'Kart ' . $kart['card_no'] . ' bu çavuşun havuzunda değil.', ['card_no' => (string)$kart['card_no']]);
        }
        if ((string)$kart['status'] === 'lost' || (string)$kart['status'] === 'disabled') {
            $pdo->rollBack();
            return pdks_ters_red('kart_kullanim_disi', 'Kart ' . $kart['card_no'] . ' ' . pdks_ters_kart_durum_adi((string)$kart['status'], $m['durum_etiketi'] ?? null)
                . ' işaretli — bulunduysa Kart Havuzu ekranından Kullanıma Al.', ['card_no' => (string)$kart['card_no']]);
        }
        $var = $pdo->prepare('SELECT id, rol FROM ters_sayim_kart WHERE sayim_id = ? AND worker_card_id = ?');
        $var->execute([$sayimId, $kartId]);
        $mevcut = $var->fetch();
        if ($mevcut && $mevcut['rol'] === 'kalan') {
            $mukerrer = true;
        } elseif ($mevcut) {   // 'teyit' (dağıtıldı kararı) verilmişti ama kart elde → kalan
            $pdo->prepare("UPDATE ters_sayim_kart SET rol = 'kalan', okutma_kaynak = ?, okutma_zamani = ?, user_id = ? WHERE id = ?")
                ->execute([$kaynak, date('Y-m-d H:i:s'), $user, (int)$mevcut['id']]);
        } else {
            $pdo->prepare('INSERT INTO ters_sayim_kart (sayim_id, worker_card_id, rol, worker_type_id_snapshot, worker_type_name_snapshot, card_no_snapshot, okutma_kaynak, okutma_zamani, user_id) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$sayimId, $kartId, 'kalan', (int)$m['worker_type_id'], pdks_ters_tip_adi($pdo, (int)$m['worker_type_id']), (string)$kart['card_no'], $kaynak, date('Y-m-d H:i:s'), $user]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (pdks_ters_eszamanli($e)) { $mukerrer = true; }   // aynı kart aynı anda iki terminalden: tek satır kalır
        else {
            error_log('[pdks_ters_sayim_okut] ' . $e->getMessage());
            return pdks_ters_red('yazma_hatasi', 'Okutma kaydedilemedi. Tekrar okutun.');
        }
    }
    $s = pdks_ters_sayim_getir($pdo, $sayimId);
    return ['ok' => true, 'mukerrer' => $mukerrer, 'card_no' => (string)$kart['card_no'], 'kart_id' => $kartId,
            'tip_adi' => pdks_ters_tip_adi($pdo, (int)($m['worker_type_id'] ?? 0)), 'sayac' => pdks_ters_sayim_sayac($pdo, $s)];
}

function pdks_ters_tip_adi(PDO $pdo, int $tipId): string
{
    static $c = [];
    if (!isset($c[$tipId])) {
        $st = $pdo->prepare('SELECT name FROM worker_types WHERE id = ?'); $st->execute([$tipId]);
        $c[$tipId] = (string)($st->fetchColumn() ?: '');
    }
    return $c[$tipId];
}

/**
 * Taslaktaki bir kartın kararını değiştirir:
 *   'kalan_sil' → yanlışlıkla okutulan kalan kart listeden çıkar
 *   'dagitildi' → dönmemiş kart için "bugün dağıtıldı" teyidi
 *   'teyit_sil' → teyidi geri al
 *   'kayip'     → dönmemiş kartı KAYIP işaretle (havuzda kalır, sayıma girmez)
 * Yetki ÇAĞIRANDA (attendance.daily_scan). Audit'li.
 */
function pdks_ters_sayim_karar(int $sayimId, int $kartId, string $karar, string $not, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    if ($pdo->inTransaction()) return pdks_ters_red('ic_ice_islem', 'Bu işlem başka bir işlemin içinde çalıştırılamaz.');
    if (!in_array($karar, ['kalan_sil', 'dagitildi', 'teyit_sil', 'kayip'], true)) return pdks_ters_red('gecersiz', 'Geçersiz işlem.');
    $not = trim($not);
    if (mb_strlen($not) > 255) return pdks_ters_red('gerekce', 'Not en fazla 255 karakter olabilir.');
    if ($karar === 'kayip') {
        $s = pdks_ters_sayim_getir($pdo, $sayimId);
        if (!$s) return pdks_ters_red('sayim_yok', 'Sayım bulunamadı.');
        if ((string)$s['durum'] !== 'taslak') return pdks_ters_red('sayim_kapali', 'Sayım taslak değil.');
        if (!pdks_ters_depo_esit((string)$s['depo'], pdks_ters_aktif_depo())) return pdks_ters_red('yanlis_depo', 'Bu sayım aktif depoya ait değil.');
        if ((string)$s['work_date'] !== date('Y-m-d')) return pdks_ters_red('eski_gun', 'Bu sayım geçmiş bir güne ait.');
        // Denetim bulgusu: sayım ekranından (daily_scan) YALNIZ dönmemiş kart kayıp işaretlenir; diğerleri Kart Havuzu (worker_cards).
        $dm = pdks_ters_donmemis($pdo, $kartId);
        if (!$dm || (string)$dm['work_date'] >= (string)$s['work_date']) return pdks_ters_red('donmemis_degil', 'Sayım ekranından yalnız önceki günden dönmemiş kart Kayıp işaretlenebilir — diğer kartlar için Kart Havuzu ekranını kullanın.');
        $r = pdks_ters_kart_durum($kartId, (int)$s['foreman_id'], 'kayip', $not !== '' ? $not : 'Sabah sayımında dönmemiş kart', $user, $pdo);
        if (!$r['ok']) return $r;
        return ['ok' => true, 'sayac' => pdks_ters_sayim_sayac($pdo, $s)];
    }
    try {
        $pdo->beginTransaction();
        $k = pdks_ters_taslak_kapi($pdo, $sayimId);
        if (!$k['ok']) { $pdo->rollBack(); return $k; }
        $s = $k['sayim'];
        $var = $pdo->prepare('SELECT id, rol FROM ters_sayim_kart WHERE sayim_id = ? AND worker_card_id = ?');
        $var->execute([$sayimId, $kartId]);
        $mevcut = $var->fetch();
        if ($karar === 'kalan_sil' || $karar === 'teyit_sil') {
            $rol = $karar === 'kalan_sil' ? 'kalan' : 'teyit';
            if (!$mevcut || $mevcut['rol'] !== $rol) { $pdo->rollBack(); return pdks_ters_red('yok', 'Kart listede bulunamadı.'); }
            $pdo->prepare('DELETE FROM ters_sayim_kart WHERE id = ?')->execute([(int)$mevcut['id']]);
        } else {   // dagitildi
            $m = pdks_gunluk_ters_havuz_aktif($pdo, $kartId);
            if (!$m || (int)$m['foreman_id'] !== (int)$s['foreman_id'] || !pdks_ters_depo_esit((string)$m['depo'], (string)$s['depo'])) {
                $pdo->rollBack(); return pdks_ters_red('havuz_disi', 'Kart bu çavuşun havuzunda değil.');
            }
            if ($mevcut) { $pdo->rollBack(); return pdks_ters_red('zaten', $mevcut['rol'] === 'kalan' ? 'Kart kalan olarak okutulmuş.' : 'Teyit zaten verilmiş.'); }
            // Denetim bulgusu: teyit YALNIZ önceki günden dönmemiş kart için (aksi hâlde normal kart zaten dağıtılmış sayılır).
            $dm = pdks_ters_donmemis($pdo, $kartId);
            if (!$dm || (string)$dm['work_date'] >= (string)$s['work_date']) { $pdo->rollBack(); return pdks_ters_red('donmemis_degil', 'Teyit yalnız önceki günden dönmemiş kartlar için verilir.'); }
            $c = $pdo->prepare('SELECT card_no FROM worker_cards WHERE id = ?'); $c->execute([$kartId]);
            $pdo->prepare('INSERT INTO ters_sayim_kart (sayim_id, worker_card_id, rol, worker_type_id_snapshot, worker_type_name_snapshot, card_no_snapshot, okutma_zamani, user_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$sayimId, $kartId, 'teyit', (int)$m['worker_type_id'], pdks_ters_tip_adi($pdo, (int)$m['worker_type_id']), (string)$c->fetchColumn(), date('Y-m-d H:i:s'), $user]);
        }
        pdks_ters_audit($pdo, $user, 'ters_sayim_karar', 'ters_sayim', $sayimId, [], ['worker_card_id' => $kartId, 'karar' => $karar, 'not' => $not]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (pdks_ters_eszamanli($e)) return pdks_ters_red('eszamanli', PDKS_TERS_ESZAMANLI);
        error_log('[pdks_ters_sayim_karar] ' . $e->getMessage());
        return pdks_ters_red('yazma_hatasi', 'İşlem kaydedilemedi. Lütfen tekrar deneyin.');
    }
    return ['ok' => true, 'sayac' => pdks_ters_sayim_sayac($pdo, $s)];
}

/** Bu çavuşun bugünkü mesaisi (yoksa null) — çavuş + gün + depo (TR-duyarsız). */
function pdks_ters_bugunku_mesai(PDO $pdo, int $foremanId, string $workDate, string $depo): ?array
{
    $st = $pdo->prepare('SELECT * FROM daily_work_sessions WHERE foreman_id = ? AND work_date = ?');
    $st->execute([$foremanId, $workDate]);
    foreach ($st->fetchAll() as $r) if (pdks_ters_depo_esit((string)$r['depo'], $depo)) return $r;
    return null;
}

/**
 * Kart "dönmemiş" mi: son (iptal edilmemiş) dönemi çıkışsız VE mesaisi kapatılmış,
 * yani kart en son dağıtıldı ve hiç geri okutulmadı. Dönem bilgisi ya da null.
 */
function pdks_ters_donmemis(PDO $pdo, int $kartId): ?array
{
    $st = $pdo->prepare(
        "SELECT p.id, p.exit_time, p.entry_time, s.work_date, s.status AS s_status, COALESCE(f.name, s.foreman_name_snapshot) AS foreman_name
           FROM daily_worker_work_periods p
           JOIN daily_work_sessions s ON s.id = p.session_id
           LEFT JOIN foremen f ON f.id = s.foreman_id
          WHERE p.worker_card_id = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p') . "
          ORDER BY p.entry_time DESC, p.id DESC LIMIT 1"
    );
    $st->execute([$kartId]);
    $p = $st->fetch();
    if (!$p || $p['exit_time'] !== null || (string)$p['s_status'] !== 'closed') return null;
    return $p;
}

/**
 * Sayımın kart dışı (mesai + zaman) ön koşulları — SALT OKUNUR. Kartla sayım
 * (pdks_ters_sayim_degerlendir) ve manuel sayım (pdks_ters_manuel_degerlendir)
 * AYNI kuralları buradan alır. @return array{0:list<string>, 1:?array} [genel hatalar, bugünkü mesai]
 */
function pdks_ters_sayim_genel_kontrol(PDO $pdo, array $sayim, ?string $girisZamani): array
{
    $foremanId = (int)$sayim['foreman_id']; $depo = (string)$sayim['depo']; $gun = (string)$sayim['work_date'];
    $genel = [];
    $mesai = pdks_ters_bugunku_mesai($pdo, $foremanId, $gun, $depo);
    $c = pdks_ters_cavus($pdo, $foremanId);
    if (!$c) $genel[] = 'Çavuş bulunamadı.';
    elseif (!$mesai && !(int)$c['is_active']) $genel[] = 'Bu çavuş pasif — önce aktifleştirin.';
    if ($mesai && (string)$mesai['status'] !== 'open') $genel[] = 'Bu çavuşun bugünkü mesaisi kapatılmış — kapalı mesaiye giriş yazılamaz.';
    if ($mesai && pdks_faz8j_entitlement($pdo, (int)$mesai['id']) === 'final') $genel[] = 'Bu mesainin kesinleşmiş hakedişi var — önce hakediş yönetici tarafından yeniden açılmalı.';
    if (!$mesai && $gun === date('Y-m-d')) {
        $eski = pdks_gunluk_eski_acik_oturumlar($depo, $foremanId, $pdo);
        if ($eski) $genel[] = 'Bu çavuşun ' . date('d.m.Y', strtotime((string)$eski[0]['work_date'])) . ' tarihli mesaisi kapatılmamış — önce o mesaiyi kapatın (Giriş-Çıkış ekranı → Mesaiyi Kapat).';
    }
    if ($girisZamani !== null) {
        if (substr($girisZamani, 0, 10) !== $gun) $genel[] = 'Giriş saati mesai gününe ait olmalı.';
        elseif (strtotime($girisZamani) > time() + PDKS_TERS_ILERI_DK * 60) {
            $genel[] = 'Sabit giriş saati şu andan en fazla ' . intdiv(PDKS_TERS_ILERI_DK, 60) . ' saat ileride olabilir.';
        }
    }
    return [$genel, $mesai];
}

/**
 * Sabah sayımını SALT OKUNUR değerlendirir (önizleme ve onay AYNI fonksiyon; onay bunu
 * kart kilitleri ALTINDA yeniden çağırır). Havuzun HER aktif üyesi sınıflanır:
 *   kalan          — elde kalan olarak okutuldu
 *   dagitilacak    — dağıtıldı, onayda sabit saatle GİRİŞ yazılacak
 *   zaten_iceride  — bu çavuşun bugünkü mesaisinde zaten açık dönemi var (normal okutma) — yeni kayıt YOK
 *   haric          — kayıp / bozuk / kullanım dışı (işçi SAYILMAZ)
 *   hata           — onayı ENGELLER (başka mesaide açık, dönmemiş kart kararsız, aynı gün eksik çıkış, …)
 * $girisZamani null ise zaman kuralları atlanır (canlı önizleme).
 */
function pdks_ters_sayim_degerlendir(PDO $pdo, array $sayim, ?string $girisZamani): array
{
    $foremanId = (int)$sayim['foreman_id']; $depo = (string)$sayim['depo']; $gun = (string)$sayim['work_date'];
    $out = ['satirlar' => [], 'hatalar' => [], 'genel' => [], 'uyarilar' => [],
            'ozet' => ['havuz_toplam' => 0, 'havuz_aktif' => 0, 'kalan' => 0, 'dagitilacak' => 0, 'zaten_iceride' => 0,
                       'haric' => 0, 'hata' => 0, 'calisan' => 0, 'tipler' => []],
            'session' => null];
    [$out['genel'], $mesai] = pdks_ters_sayim_genel_kontrol($pdo, $sayim, $girisZamani);
    $out['session'] = $mesai;

    $satirlar = pdks_ters_sayim_satirlari($pdo, (int)$sayim['id']);
    $uyeler = pdks_ters_havuz_uyeleri($pdo, $foremanId, $depo);
    $stK = $pdo->prepare('SELECT * FROM worker_cards WHERE id = ?');
    $stB = $pdo->prepare("SELECT p.entry_time, p.exit_time, COALESCE(f.name, s.foreman_name_snapshot) AS foreman_name
                            FROM daily_worker_work_periods p JOIN daily_work_sessions s ON s.id = p.session_id LEFT JOIN foremen f ON f.id = s.foreman_id
                           WHERE p.worker_card_id = ? AND p.work_date_snapshot = ? AND p.exit_time IS NOT NULL AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p') . "
                           ORDER BY p.exit_time DESC LIMIT 1");
    $uyeIds = [];
    foreach ($uyeler as $u) {
        $kid = (int)$u['worker_card_id']; $uyeIds[$kid] = true;
        $r = ['kart_id' => $kid, 'card_no' => (string)$u['card_no'], 'tip_id' => (int)$u['worker_type_id'], 'tip_adi' => (string)$u['tip_adi'],
              'durum' => '', 'sebep' => null, 'teyit' => false, 'donmemis' => null, 'period_id' => null];
        $sr = $satirlar[$kid] ?? null;
        $out['ozet']['havuz_toplam']++;
        if ($u['status'] === 'lost' || $u['status'] === 'disabled') {
            $r['durum'] = 'haric'; $r['sebep'] = pdks_ters_kart_durum_adi((string)$u['status'], $u['durum_etiketi']);
            if ($sr && $sr['rol'] === 'kalan') $out['uyarilar'][] = 'Kart ' . $u['card_no'] . ' kalan okutulmuştu ama artık ' . $r['sebep'] . ' — sayılmıyor.';
        } else {
            $out['ozet']['havuz_aktif']++;
            if ($sr && $sr['rol'] === 'kalan') {
                $r['durum'] = 'kalan';
                $acikK = pdks_gunluk_faz8a_kart_acik_donemi($pdo, $kid);
                if ($acikK) $out['uyarilar'][] = 'Kart ' . $u['card_no'] . ' kalan okutuldu ama şu an ' . $acikK['foreman_name'] . ' mesaisinde içeride görünüyor — kartın gerçekten elde olduğunu kontrol edin.';
            } else {
                $r['teyit'] = (bool)($sr && $sr['rol'] === 'teyit');
                $acik = pdks_gunluk_faz8a_kart_acik_donemi($pdo, $kid);
                if ($acik && $mesai && (int)$acik['session_id'] === (int)$mesai['id']) {
                    $r['durum'] = 'zaten_iceride'; $r['period_id'] = (int)$acik['id'];
                } elseif ($acik) {
                    $r['durum'] = 'hata'; $r['sebep'] = 'Kart ' . $acik['foreman_name'] . ' mesaisinde açık görünüyor (başka mesaide içeride).';
                } elseif (($d = pdks_ters_donmemis($pdo, $kid)) && (string)$d['work_date'] === $gun) {
                    $r['durum'] = 'hata'; $r['sebep'] = 'Kart bugün eksik çıkışla kapatılan bir mesaide kaldı — aynı gün yeniden giriş yapılamaz.';
                } elseif ($d && !$r['teyit']) {
                    $r['durum'] = 'hata'; $r['donmemis'] = ['work_date' => (string)$d['work_date'], 'foreman_name' => (string)$d['foreman_name']];
                    $r['sebep'] = 'Kart ' . date('d.m.Y', strtotime((string)$d['work_date'])) . ' tarihinden beri dönmedi (' . $d['foreman_name']
                                . ') — elde değilse KAYIP işaretleyin ya da "Bugün dağıtıldı" diye teyit edin.';
                } elseif (!pdks_faz8j_desteklenen_tip($pdo, (int)$u['worker_type_id'])) {
                    $r['durum'] = 'hata'; $r['sebep'] = 'Kartın havuz tipi pasif ya da desteklenmiyor — Kart Havuzu ekranından tipi düzeltin.';
                } else {
                    $stK->execute([$kid]);
                    $kontrol = $girisZamani !== null
                        ? pdks_faz8j_satir_kontrol($pdo, $mesai ? (int)$mesai['id'] : null, $gun,
                            ['kartsiz' => false, 'kart' => $stK->fetch() ?: null, 'entry' => $girisZamani, 'exit' => null],
                            ['foreman_id' => $foremanId, 'depo' => $mesai ? (string)$mesai['depo'] : $depo])
                        : pdks_faz8j_tanim_kart_engeli($pdo, $kid, $foremanId, $depo);
                    if ($kontrol !== null) { $r['durum'] = 'hata'; $r['sebep'] = $kontrol; }
                    else {
                        $r['durum'] = 'dagitilacak';
                        // Denetim bulgusu: bugün zaten çalışıp çıkmış kart (normal okutma) → ikinci katılım. ENGEL DEĞİL (sahip kararı: karışık gün serbest).
                        $stB->execute([$kid, $gun]);
                        if ($b = $stB->fetch()) {
                            $out['uyarilar'][] = 'Kart ' . $u['card_no'] . ' bugün ' . substr((string)$b['entry_time'], 11, 5) . '–' . substr((string)$b['exit_time'], 11, 5)
                                . ' arasında zaten çalıştı (' . $b['foreman_name'] . ') — onaylanırsa İKİNCİ katılım yazılır. Kart elde ise kalan olarak okutun.';
                        }
                    }
                }
            }
        }
        $out['ozet'][$r['durum']]++;
        if ($r['durum'] === 'dagitilacak' || $r['durum'] === 'zaten_iceride') {
            $out['ozet']['tipler'][$r['tip_adi']] = ($out['ozet']['tipler'][$r['tip_adi']] ?? 0) + 1;
        }
        if ($r['durum'] === 'hata') $out['hatalar'][] = $r;
        $out['satirlar'][] = $r;
    }
    // Havuzdan çıkarılmış ama taslakta kalan okutmalar → onayı engeller (yanlış sayım olmasın).
    foreach ($satirlar as $kid => $sr) {
        if (isset($uyeIds[$kid])) continue;
        $r = ['kart_id' => $kid, 'card_no' => (string)$sr['card_no_snapshot'], 'tip_id' => (int)$sr['worker_type_id_snapshot'],
              'tip_adi' => (string)$sr['worker_type_name_snapshot'], 'durum' => 'hata', 'teyit' => false, 'donmemis' => null, 'period_id' => null,
              'sebep' => 'Kart artık bu çavuşun havuzunda değil — listeden çıkarın.', 'havuz_disi' => true, 'rol' => (string)$sr['rol']];
        $out['ozet']['hata']++;
        $out['hatalar'][] = $r; $out['satirlar'][] = $r;
    }
    $out['ozet']['calisan'] = $out['ozet']['dagitilacak'] + $out['ozet']['zaten_iceride'];
    if ($out['ozet']['havuz_aktif'] === 0) $out['genel'][] = 'Havuzda kullanılabilir kart yok.';
    $out['ok'] = !$out['genel'] && !$out['hatalar'];
    return $out;
}

/** Onayda kullanılacak giriş saati + gerekçe kuralı (TEK yer). */
function pdks_ters_giris_coz(array $sayim, string $saatHam, string $neden): array
{
    $saat = pdks_ters_saat($saatHam);
    if ($saat === null) return pdks_ters_red('saat_gecersiz', 'Sabit giriş saatini SS:DD biçiminde girin (ör. 08:00).');
    $vars = $sayim['varsayilan_giris_saati'] !== null ? (string)$sayim['varsayilan_giris_saati'] : null;
    $neden = trim($neden);
    if (mb_strlen($neden) > 255) return pdks_ters_red('gerekce', 'Açıklama en fazla 255 karakter olabilir.');
    if ($vars !== null && $saat !== $vars && $neden === '') {
        return pdks_ters_red('gerekce', 'Giriş saati çavuşun tanımlı saatinden (' . $vars . ') farklı — gerekçe yazın.');
    }
    // Denetim bulgusu: tanımlı saat yoksa elle girilen her saat gerekçe ister (audit'te kalır).
    if ($vars === null && $neden === '') {
        return pdks_ters_red('gerekce', 'Bu çavuşa tanımlı giriş saati yok — elle girilen saat için gerekçe yazın (kalıcı ayar: Kart Havuzu).');
    }
    return ['ok' => true, 'saat' => $saat, 'zaman' => (string)$sayim['work_date'] . ' ' . $saat . ':00', 'neden' => $neden, 'degisti' => $vars !== null && $saat !== $vars];
}

/** Beyan sayısı (isteğe bağlı): boş → null; 0-2000 tam sayı. */
function pdks_ters_beyan($x): array
{
    $v = pdks_ters_metin($x);
    if ($v === '') return ['ok' => true, 'beyan' => null];
    if (!preg_match('/^\d{1,4}$/D', $v) || (int)$v > 2000) return pdks_ters_red('beyan_gecersiz', 'Çavuşun getirdiği işçi sayısı 0-2000 arası tam sayı olmalı.');
    return ['ok' => true, 'beyan' => (int)$v];
}

/** Beyan farkı uyarısı (ENGEL DEĞİL — sahip kararı). */
function pdks_ters_beyan_uyarisi(?int $beyan, int $calisan): ?string
{
    if ($beyan === null || $beyan === $calisan) return null;
    return 'Çavuşun beyan ettiği işçi sayısı (' . $beyan . ') dağıtılan kart sayısından (' . $calisan . ') farklı — '
         . ($beyan < $calisan ? 'fark kayıp/dağıtılmamış kart olabilir; elde olmayan kartı kontrol edin.' : 'kartsız çalışan olabilir; kontrol edin.');
}

/** Önizleme — HİÇBİR şey yazmaz. */
function pdks_ters_sayim_onizle(int $sayimId, string $saatHam, string $neden, $beyanHam, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    $s = pdks_ters_sayim_getir($pdo, $sayimId);
    if (!$s) return pdks_ters_red('sayim_yok', 'Sayım bulunamadı.');
    if (!pdks_ters_depo_esit((string)$s['depo'], pdks_ters_aktif_depo())) return pdks_ters_red('yanlis_depo', 'Bu sayım aktif depoya ait değil.');
    $g = $saatHam !== '' ? pdks_ters_giris_coz($s, $saatHam, $neden) : ['ok' => true, 'zaman' => null];
    $b = pdks_ters_beyan($beyanHam);
    $d = pdks_ters_sayim_degerlendir($pdo, $s, $g['ok'] ? $g['zaman'] : null);
    if (!$g['ok']) { $d['genel'][] = $g['hata']; $d['ok'] = false; }
    if (!$b['ok']) { $d['genel'][] = $b['hata']; $d['ok'] = false; }
    elseif ($u = pdks_ters_beyan_uyarisi($b['beyan'], $d['ozet']['calisan'])) $d['uyarilar'][] = $u;
    unset($d['session']);
    return ['ok' => true, 'degerlendirme' => $d, 'sayim' => $s];
}

/**
 * ONAY — HEP-YA-HİÇ, tek transaction. Sıra (MySQL InnoDB REPEATABLE READ: tüm
 * KİLİTLİ okumalar tutarlı okumadan ÖNCE yapılır ki sonraki okumalar son commit'i görsün):
 *   1. sayım satırı FOR UPDATE (iki operatörün eşzamanlı onayı tek satıra düşer)
 *   2. mesai satırı FOR UPDATE (pdks_faz8j_oturum_kilitle — kiosk "Mesaiyi Kapat"ı ile yarış)
 *   3. havuz üyelikleri FOR UPDATE + kartlar ARTAN id sırasıyla (kiosk girişiyle AYNI kart kilidi)
 *   4. değerlendirme kilit ALTINDA yeniden — tek hata bile varsa HİÇBİR ŞEY yazılmaz
 *   5. mesai: kiosk yolu (pdks_faz8j_oturum_coz → pdks_gunluk_oturum_ac_veya_getir)
 *   6. dağıtılan her kart: pdks_faz8j_satir_kontrol + pdks_faz8j_satir_yaz (kaynak 'ters')
 *   7. sayım anlık görüntüsü (ters_sayim_kart) + sayım satırı + özet audit
 * Tekrar gönderim: aynı istek_id ile onaylanmış sayım → aynı sonuç ('tekrar').
 * Yetki ÇAĞIRANDA (attendance.daily_scan).
 */
function pdks_ters_sayim_onayla(int $sayimId, string $saatHam, string $neden, $beyanHam, string $istekIdHam, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    if ($pdo->inTransaction()) return pdks_ters_red('ic_ice_islem', 'Bu işlem başka bir işlemin içinde çalıştırılamaz.');
    $istekId = pdks_ters_istek_id($istekIdHam);
    if ($istekId === null) return pdks_ters_red('istek_gecersiz', 'Geçersiz istek anahtarı; sayfayı yenileyip tekrar deneyin.');
    $b = pdks_ters_beyan($beyanHam);
    if (!$b['ok']) return $b;
    $depoAktif = pdks_ters_aktif_depo();
    if ($depoAktif === '') return pdks_ters_red('depo_yok', 'Önce bir depo seçmelisiniz.');
    $simdi = date('Y-m-d H:i:s');
    $kilit = pdks_ters_kilit_ek($pdo);

    try {
        $pdo->beginTransaction();
        // 1 — sayım satırı
        $s = pdks_ters_sayim_getir($pdo, $sayimId, true);
        if (!$s) throw new RuntimeException('Sayım bulunamadı.');
        if ((string)$s['durum'] === 'onayli') {
            $pdo->rollBack();
            if ((string)$s['istek_id'] === $istekId) {
                return ['ok' => true, 'tekrar' => true, 'sayim_id' => $sayimId, 'session_id' => (int)$s['session_id'],
                        'ozet' => pdks_ters_sayim_ozet_satiri($s)];
            }
            return pdks_ters_red('zaten_onayli', 'Bu sayım başka bir işlemle zaten onaylandı.');
        }
        if (pdks_ters_sayim_manuel_mi($s)) { $pdo->rollBack(); return pdks_ters_red('zaten_onayli', 'Bu sayım manuel sayım olarak zaten onaylandı.'); }
        if ((string)$s['durum'] !== 'taslak') throw new RuntimeException('Bu sayım geri alınmış — yeni sayımı kullanın.');
        if (!pdks_ters_depo_esit((string)$s['depo'], $depoAktif)) throw new RuntimeException('Bu sayım aktif depoya ait değil.');
        if ((string)$s['work_date'] !== date('Y-m-d')) throw new RuntimeException('Bu sayım geçmiş bir güne ait — onaylanamaz.');
        $g = pdks_ters_giris_coz($s, $saatHam, $neden);
        if (!$g['ok']) throw new RuntimeException($g['hata']);
        $foremanId = (int)$s['foreman_id']; $gun = (string)$s['work_date']; $depo = (string)$s['depo'];
        // 2 — mesai satırı (KİLİTLİ okuma; kilitsiz okuma burada anlık görüntüyü erken sabitlerdi).
        // Mesai kiosk yoluyla AYNI depo dizgesiyle aranır/açılır: varsa kendi dizgesi, yoksa active_depot().
        $stM = $pdo->prepare('SELECT id, depo FROM daily_work_sessions WHERE foreman_id = ? AND work_date = ?' . $kilit);
        $stM->execute([$foremanId, $gun]);
        $mesaiDepo = $depoAktif;
        foreach ($stM->fetchAll() as $m0) if (pdks_ters_depo_esit((string)$m0['depo'], $depo)) { $mesaiDepo = (string)$m0['depo']; break; }
        // 3 — üyelik + kart kilitleri (artan id)
        $stU = $pdo->prepare('SELECT worker_card_id, depo FROM ters_havuz_kart WHERE foreman_id = ? AND aktif_kart_id IS NOT NULL ORDER BY worker_card_id ASC' . $kilit);
        $stU->execute([$foremanId]);
        $kartIds = [];
        foreach ($stU->fetchAll() as $r) if (pdks_ters_depo_esit((string)$r['depo'], $depo)) $kartIds[] = (int)$r['worker_card_id'];
        foreach ($kartIds as $kid) pdks_gunluk_faz8a_kart_kilitle($pdo, $kid);

        // 4 — kilit altında değerlendirme
        $d = pdks_ters_sayim_degerlendir($pdo, $s, $g['zaman']);
        if (!$d['ok']) {
            $pdo->rollBack();
            return pdks_ters_red('onaylanamaz', $d['genel'] ? implode(' ', $d['genel']) : count($d['hatalar']) . ' kartta sorun var — hiçbir giriş yazılmadı.',
                ['degerlendirme' => array_diff_key($d, ['session' => 1])]);
        }
        if ($d['ozet']['calisan'] === 0) {
            $pdo->rollBack();
            return pdks_ters_red('dagitilan_yok', 'Dağıtılmış kart yok (havuzdaki tüm kartlar elde) — onaylanacak giriş yok.');
        }

        // 5 — mesai (kiosk yolu)
        $oc = pdks_faz8j_oturum_coz($pdo, $foremanId, $gun, $mesaiDepo, $user, true);
        if (!$oc['ok']) throw new RuntimeException((string)$oc['hata']);
        $oturum = $oc['oturum']; $sid = (int)$oturum['id'];
        if (pdks_faz8j_entitlement($pdo, $sid) === 'final') {
            throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi var — önce hakediş yönetici tarafından yeniden açılmalı.');
        }

        // 6 — girişler (TEK yazma yolu)
        $topluId = 'TS' . $sayimId;
        $stK = $pdo->prepare('SELECT * FROM worker_cards WHERE id = ?');
        $insK = $pdo->prepare('INSERT INTO ters_sayim_kart (sayim_id, worker_card_id, rol, teyitli, haric_sebep, worker_type_id_snapshot, worker_type_name_snapshot, card_no_snapshot, period_id, okutma_zamani, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $updK = $pdo->prepare("UPDATE ters_sayim_kart SET rol = 'dagitilan', teyitli = 1, period_id = ? WHERE sayim_id = ? AND worker_card_id = ? AND rol = 'teyit'");
        $yeni = 0;
        foreach ($d['satirlar'] as $r) {
            $kid = (int)$r['kart_id'];
            if ($r['durum'] === 'kalan') continue;   // taslakta zaten var
            if ($r['durum'] === 'dagitilacak') {
                $stK->execute([$kid]); $kart = $stK->fetch();
                $tip = pdks_faz8j_desteklenen_tip($pdo, (int)$r['tip_id']);
                $satir = ['kartsiz' => false, 'kart' => $kart, 'tip' => $tip, 'entry' => $g['zaman'], 'exit' => null];
                if ($h = pdks_faz8j_satir_kontrol($pdo, $sid, $gun, $satir, ['foreman_id' => $foremanId, 'depo' => (string)$oturum['depo']])) {
                    throw new RuntimeException('Kart ' . $r['card_no'] . ': ' . $h);
                }
                $pid = pdks_faz8j_satir_yaz($pdo, $oturum, $satir, $user, [
                    'reason' => PDKS_TERS_SAYIM_NOTU, 'note' => '', 'yeni_mesai' => $oc['yeni'], 'simdi' => $simdi,
                    'toplu_id' => $topluId, 'istek_id' => $istekId,
                    'kaynak' => PDKS_TERS_KAYNAK, 'olay_zamani' => $simdi, 'audit_action' => 'ters_giris',
                ]);
                $yeni++;
                if ($r['teyit']) $updK->execute([$pid, $sayimId, $kid]);
                else $insK->execute([$sayimId, $kid, 'dagitilan', 0, null, (int)$r['tip_id'], (string)$r['tip_adi'], (string)$r['card_no'], $pid, null, $user]);
            } elseif ($r['durum'] === 'zaten_iceride') {
                if ($r['teyit']) $pdo->prepare("DELETE FROM ters_sayim_kart WHERE sayim_id = ? AND worker_card_id = ? AND rol = 'teyit'")->execute([$sayimId, $kid]);
                $insK->execute([$sayimId, $kid, 'zaten_iceride', 0, null, (int)$r['tip_id'], (string)$r['tip_adi'], (string)$r['card_no'], (int)$r['period_id'], null, $user]);
            } elseif ($r['durum'] === 'haric') {
                $pdo->prepare('DELETE FROM ters_sayim_kart WHERE sayim_id = ? AND worker_card_id = ?')->execute([$sayimId, $kid]);
                $insK->execute([$sayimId, $kid, 'haric', 0, mb_substr((string)$r['sebep'], 0, 30), (int)$r['tip_id'], (string)$r['tip_adi'], (string)$r['card_no'], null, null, $user]);
            }
        }
        pdks_faz8j_yeniden_hesap_isaretle($pdo, $sid);

        // 7 — sayım satırı + özet audit
        $oz = $d['ozet'];
        $pdo->prepare("UPDATE ters_sayim SET durum = 'onayli', session_id = ?, giris_saati = ?, giris_zamani = ?, giris_saati_nedeni = ?,
                              havuz_aktif = ?, kalan_adet = ?, dagitilan_adet = ?, yeni_giris_adet = ?, zaten_iceride_adet = ?, haric_adet = ?,
                              beyan_adet = ?, istek_id = ?, onaylayan_user_id = ?, onay_zamani = ?
                        WHERE id = ? AND durum = 'taslak'")
            ->execute([$sid, $g['saat'], $g['zaman'], $g['neden'] !== '' ? $g['neden'] : null,
                       $oz['havuz_aktif'], $oz['kalan'], $oz['calisan'], $yeni, $oz['zaten_iceride'], $oz['haric'],
                       $b['beyan'], $istekId, $user, $simdi, $sayimId]);
        $uyarilar = $d['uyarilar'];
        if ($bu = pdks_ters_beyan_uyarisi($b['beyan'], $oz['calisan'])) $uyarilar[] = $bu;
        pdks_ters_audit($pdo, $user, 'ters_sayim_onay', 'ters_sayim', $sayimId, [], [
            'istek_id' => $istekId, 'toplu_id' => $topluId, 'foreman_id' => $foremanId, 'depo' => $depo, 'work_date' => $gun,
            'session_id' => $sid, 'yeni_mesai' => $oc['yeni'], 'giris_saati' => $g['saat'], 'varsayilan_giris_saati' => $s['varsayilan_giris_saati'],
            'giris_saati_nedeni' => $g['neden'], 'havuz_aktif' => $oz['havuz_aktif'], 'kalan' => $oz['kalan'], 'dagitilan' => $oz['calisan'],
            'yeni_giris' => $yeni, 'zaten_iceride' => $oz['zaten_iceride'], 'haric' => $oz['haric'], 'beyan' => $b['beyan'], 'onay_zamani' => $simdi,
        ]);
        $pdo->commit();
        return ['ok' => true, 'sayim_id' => $sayimId, 'session_id' => $sid, 'yeni_mesai' => $oc['yeni'],
                'ozet' => ['havuz_aktif' => $oz['havuz_aktif'], 'kalan' => $oz['kalan'], 'dagitilan' => $oz['calisan'], 'yeni_giris' => $yeni,
                           'zaten_iceride' => $oz['zaten_iceride'], 'haric' => $oz['haric'], 'tipler' => $oz['tipler'],
                           'giris_saati' => $g['saat'], 'onay_zamani' => $simdi],
                'uyarilar' => $uyarilar];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException && !$e instanceof PDOException) return pdks_ters_red('onaylanamaz', $e->getMessage());
        if (pdks_ters_eszamanli($e)) return pdks_ters_red('eszamanli', PDKS_TERS_ESZAMANLI);
        error_log('[pdks_ters_sayim_onayla] ' . $e->getMessage());
        return pdks_ters_red('yazma_hatasi', 'Onay sırasında teknik bir hata oluştu — hiçbir giriş yazılmadı. Lütfen tekrar deneyin.');
    }
}

/** Onaylı sayım satırından özet (tekrar gönderim yanıtı / listeler). */
function pdks_ters_sayim_ozet_satiri(array $s): array
{
    return ['havuz_aktif' => (int)$s['havuz_aktif'], 'kalan' => (int)$s['kalan_adet'], 'dagitilan' => (int)$s['dagitilan_adet'],
            'yeni_giris' => (int)$s['yeni_giris_adet'], 'zaten_iceride' => (int)$s['zaten_iceride_adet'], 'haric' => (int)$s['haric_adet'],
            'giris_saati' => (string)$s['giris_saati'], 'onay_zamani' => (string)$s['onay_zamani']];
}

/**
 * Onaylı sayımı GERİ ALIR (sahip kararı: ilk çıkış okutulana kadar operatör — daily_scan —
 * gerekçeyle; sonrası yalnız yönetici, Mesai Detayı'ndaki mevcut düzeltme araçlarıyla).
 * Bu sayımın yazdığı dönemler İPTAL edilir (pdks_faz8j_void_uygula — SİLİNMEZ), sayım
 * 'geri_alindi' olur ve kalan/teyit okutmaları kopyalanmış YENİ bir taslak açılır.
 * 'zaten_iceride' dönemlere (normal okutma) DOKUNULMAZ.
 */
function pdks_ters_sayim_geri_al(int $sayimId, string $reason, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    if ($pdo->inTransaction()) return pdks_ters_red('ic_ice_islem', 'Bu işlem başka bir işlemin içinde çalıştırılamaz.');
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > PDKS_TERS_GEREKCE_MAX) return pdks_ters_red('gerekce', 'Geri alma gerekçesi zorunludur (en fazla 500 karakter).');
    $depoAktif = pdks_ters_aktif_depo();
    if ($depoAktif === '') return pdks_ters_red('depo_yok', 'Önce bir depo seçmelisiniz.');
    try {
        $pdo->beginTransaction();
        $s = pdks_ters_sayim_getir($pdo, $sayimId, true);
        if (!$s) throw new RuntimeException('Sayım bulunamadı.');
        if (pdks_ters_sayim_manuel_mi($s)) {   // v363: manuel sayımın kendi (dönem yazmayan) geri alması
            if (!pdks_ters_depo_esit((string)$s['depo'], $depoAktif)) throw new RuntimeException('Bu sayım aktif depoya ait değil.');
            $r = pdks_ters_manuel_geri_al_tx($pdo, $s, $reason, $user);
            $pdo->commit();
            return $r;
        }
        if ((string)$s['durum'] !== 'onayli') throw new RuntimeException('Yalnız onaylanmış sayım geri alınabilir.');
        if (!pdks_ters_depo_esit((string)$s['depo'], $depoAktif)) throw new RuntimeException('Bu sayım aktif depoya ait değil.');
        $sid = (int)$s['session_id']; $depo = (string)$s['depo'];
        pdks_faz8j_oturum_kilitle($pdo, (int)$s['foreman_id'], (string)$s['work_date'], $depo);
        $stR = $pdo->prepare("SELECT worker_card_id, period_id FROM ters_sayim_kart WHERE sayim_id = ? AND rol = 'dagitilan' AND period_id IS NOT NULL ORDER BY worker_card_id ASC");
        $stR->execute([$sayimId]);
        $donemler = $stR->fetchAll();
        foreach ($donemler as $r) pdks_gunluk_faz8a_kart_kilitle($pdo, (int)$r['worker_card_id']);
        if (pdks_faz8j_entitlement($pdo, $sid) === 'final') {
            throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi var — geri alınamaz. Hakediş yönetici tarafından yeniden açılmalı.');
        }
        $stS = $pdo->prepare('SELECT * FROM daily_work_sessions WHERE id = ?'); $stS->execute([$sid]); $mesai = $stS->fetch();
        if (!$mesai || (string)$mesai['status'] !== 'open') throw new RuntimeException('Mesai kapatılmış — sayım geri alınamaz. Düzeltme yönetici tarafından Mesai Detayı\'ndan yapılır.');
        $plan = [];
        foreach ($donemler as $r) {
            $p = pdks_faz8j_donem($pdo, (int)$r['period_id'], $sid, (string)$mesai['depo']);
            if (!$p) throw new RuntimeException('Sayımın bir dönemi bulunamadı — yönetici Mesai Detayı\'ndan düzeltmeli.');
            if ((int)$p['is_voided']) continue;   // yönetici elle iptal etmiş
            if ($p['exit_event_id'] !== null) {
                throw new RuntimeException('Çıkış okutulmaya başlandı (kart ' . $p['card_no'] . ') — sayım artık geri alınamaz. Düzeltme yalnız yönetici tarafından Mesai Detayı\'ndan yapılır.');
            }
            $plan[] = $p;
        }
        $topluId = 'TS' . $sayimId;
        foreach ($plan as $p) {
            pdks_faz8j_void_uygula($pdo, $p, (int)$p['id'], $sid, 'Ters sayım geri alındı: ' . $reason, $user, ['toplu_id' => $topluId, 'ters_sayim_id' => $sayimId]);
        }
        $simdi = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE ters_sayim SET durum = 'geri_alindi', aktif_anahtar = NULL, geri_alan_user_id = ?, geri_alma_zamani = ?, geri_alma_nedeni = ? WHERE id = ? AND durum = 'onayli'")
            ->execute([$user, $simdi, $reason, $sayimId]);
        // Yeni taslak — kalan + teyit okutmaları kopyalanır (operatör yalnız yanlışı düzeltir).
        $yeniId = 0;
        if ((string)$s['work_date'] === date('Y-m-d')) {
            $pdo->prepare('INSERT INTO ters_sayim (foreman_id, depo, work_date, durum, aktif_anahtar, varsayilan_giris_saati, onceki_sayim_id, olusturan_user_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([(int)$s['foreman_id'], $depo, (string)$s['work_date'], 'taslak', pdks_ters_anahtar((int)$s['foreman_id'], (string)$s['work_date'], $depo),
                           $s['varsayilan_giris_saati'], $sayimId, $user]);
            $yeniId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO ters_sayim_kart (sayim_id, worker_card_id, rol, worker_type_id_snapshot, worker_type_name_snapshot, card_no_snapshot, okutma_kaynak, okutma_zamani, user_id)
                           SELECT ?, worker_card_id, CASE WHEN teyitli = 1 THEN 'teyit' ELSE rol END, worker_type_id_snapshot, worker_type_name_snapshot, card_no_snapshot, okutma_kaynak, okutma_zamani, user_id
                             FROM ters_sayim_kart WHERE sayim_id = ? AND (rol = 'kalan' OR (rol = 'dagitilan' AND teyitli = 1))")
                ->execute([$yeniId, $sayimId]);
        }
        pdks_ters_audit($pdo, $user, 'ters_sayim_geri_al', 'ters_sayim', $sayimId, ['durum' => 'onayli'],
            ['durum' => 'geri_alindi', 'reason' => $reason, 'iptal_edilen_donem' => count($plan), 'yeni_sayim_id' => $yeniId, 'toplu_id' => $topluId]);
        $pdo->commit();
        return ['ok' => true, 'iptal_edilen' => count($plan), 'yeni_sayim_id' => $yeniId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException && !$e instanceof PDOException) return pdks_ters_red('geri_alinamaz', $e->getMessage());
        if (pdks_ters_eszamanli($e)) return pdks_ters_red('eszamanli', PDKS_TERS_ESZAMANLI);
        error_log('[pdks_ters_sayim_geri_al] ' . $e->getMessage());
        return pdks_ters_red('yazma_hatasi', 'Geri alma sırasında teknik bir hata oluştu — hiçbir şey değişmedi.');
    }
}

// =========================================================
// v363 — MANUEL SAYIM (kartsız sayım) — kartla sayımın GÜNLÜK alternatifi
// =========================================================
// Sabah kartları tek tek okutmak yerine çavuşun elinde KALAN kart SAYISI girilir:
// havuz aktif 50, kalan 20 → 30 kişi çalışıyor. Sabah HİÇBİR dönem yazılmaz.
// Akşam Ortak Çıkış'ta bu çavuşun havuz kartı (açık dönemi YOKSA) çıkış okuttuğunda
// sabit giriş saati + gerçek çıkış saatiyle KAPALI bir dönem yazılır — en fazla
// "manuel limit" (= dağıtılan − sabah zaten normal okutmayla içeride olan) kart.
// Gün sonunda dağıtılan kartların listesi çıkışlardan kendiliğinden oluşur; çıkışı
// okutulmayan kişi için dönem/çıkış UYDURULMAZ.
//
// ⚠ ŞEMA DEĞİŞMEDİ: ters_sayim.durum = 'manuel' (onaylı manuel sayım; aktif_anahtar dolu),
//   çıkışlar ters_sayim_kart.rol = 'manuel_cikis' (UNIQUE(sayim_id, worker_card_id) =
//   aynı kartın ikinci çıkışına yapısal engel).
// ⚠ İKİNCİ YAZMA YOLU YOK: dönem/olay → pdks_faz8j_satir_kontrol + pdks_faz8j_satir_yaz
//   (kaynak 'ters', audit 'ters_manuel_cikis'); mesai → pdks_faz8j_oturum_coz (kiosk yolu).
//   pdks_gunluk_ortak_cikis_kaydet() DEĞİŞMEDİ — manuel çıkış onun DIŞINDA, yalnız o
//   'acik_donem_yok' döndürdüğünde denenir (pdks_ters_ortak_cikis_manuel_yedek).
// ⚠ Taslakta okutulmuş kalan/teyit satırları manuel onayda SİLİNİR (listesi onay audit'inde
//   kalır): UNIQUE(sayim_id, worker_card_id) aynı kartın akşam manuel_cikis satırını engellerdi.

/** Manuel sayımda akşam en fazla kaç kart çıkış yapabilir (dağıtılan − zaten içeride). */
function pdks_ters_manuel_limit(array $s): int
{
    return max(0, (int)$s['dagitilan_adet'] - (int)$s['zaten_iceride_adet']);
}

/** Manuel sayımın etkin (iptal edilmemiş) çıkış sayısı. */
function pdks_ters_manuel_cikan(PDO $pdo, int $sayimId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM ters_sayim_kart k JOIN daily_worker_work_periods p ON p.id = k.period_id
                          WHERE k.sayim_id = ? AND k.rol = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p'));
    $st->execute([$sayimId, PDKS_TERS_ROL_MANUEL_CIKIS]);
    return (int)$st->fetchColumn();
}

/** Manuel sayımla çıkış yapmış kartlar (kart no doğal sırada). */
function pdks_ters_manuel_cikanlar(PDO $pdo, int $sayimId): array
{
    $st = $pdo->prepare("SELECT k.worker_card_id, k.card_no_snapshot AS card_no, k.worker_type_name_snapshot AS tip, k.period_id,
                                p.entry_time, p.exit_time, p.is_voided
                           FROM ters_sayim_kart k JOIN daily_worker_work_periods p ON p.id = k.period_id
                          WHERE k.sayim_id = ? AND k.rol = ?");
    $st->execute([$sayimId, PDKS_TERS_ROL_MANUEL_CIKIS]);
    $rows = $st->fetchAll();
    usort($rows, fn($a, $b) => strnatcasecmp((string)$a['card_no'], (string)$b['card_no']));
    return $rows;
}

/** Elde kalan kart sayısı girdisi: boş → null (henüz girilmedi); 0-9999 tam sayı. */
function pdks_ters_kalan_adet($x): array
{
    $v = pdks_ters_metin($x);
    if ($v === '') return ['ok' => true, 'kalan' => null];
    if (!preg_match('/^\d{1,4}$/D', $v)) return pdks_ters_red('kalan_gecersiz', 'Elde kalan kart sayısı 0 ya da pozitif bir tam sayı olmalı.');
    return ['ok' => true, 'kalan' => (int)$v];
}

/**
 * Manuel sayımı SALT OKUNUR değerlendirir (önizleme ve onay AYNI fonksiyon; onay bunu
 * kilitler ALTINDA yeniden çağırır). Mesai/zaman kuralları kartla sayımla AYNI
 * (pdks_ters_sayim_genel_kontrol). $kalan null → sayı henüz girilmedi (ok=false).
 * Kart bazında bilinemeyenler (başka mesaide içeride, dönmemiş, desteklenmeyen tip)
 * ENGEL DEĞİL, UYARI: bu kartlar akşam manuel çıkış yapamaz.
 */
function pdks_ters_manuel_degerlendir(PDO $pdo, array $sayim, ?int $kalan, ?string $girisZamani): array
{
    $foremanId = (int)$sayim['foreman_id']; $depo = (string)$sayim['depo']; $gun = (string)$sayim['work_date'];
    [$genel, $mesai] = pdks_ters_sayim_genel_kontrol($pdo, $sayim, $girisZamani);
    $uyeler = pdks_ters_havuz_uyeleri($pdo, $foremanId, $depo);
    $hoz = pdks_ters_havuz_ozet($uyeler);
    $oz = ['havuz_toplam' => $hoz['toplam'], 'havuz_aktif' => $hoz['aktif'], 'haric' => $hoz['toplam'] - $hoz['aktif'],
           'kalan' => $kalan, 'dagitilan' => null, 'zaten_iceride' => 0, 'manuel_limit' => null, 'tipler' => $hoz['tipler']];
    $baska = []; $donmemis = []; $bugunEksik = []; $tipsiz = []; $eksikli = []; $zatenKartlar = [];
    // Düzeltilmemiş eksik çıkış (kapatılmış mesaide çıkışsız kalmış, iptal edilmemiş dönem) — kartın SON dönemi olmasa da.
    $stEk = $pdo->prepare("SELECT COUNT(*) FROM daily_worker_work_periods p JOIN daily_work_sessions s ON s.id = p.session_id
                            WHERE p.worker_card_id = ? AND p.exit_time IS NULL AND s.status <> 'open' AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p'));
    foreach ($uyeler as $u) {
        if ($u['status'] !== 'available') continue;
        $kid = (int)$u['worker_card_id'];
        $acik = pdks_gunluk_faz8a_kart_acik_donemi($pdo, $kid);
        if ($acik && $mesai && (int)$acik['session_id'] === (int)$mesai['id']) {
            $oz['zaten_iceride']++;
            $zatenKartlar[] = ['kart_id' => $kid, 'period_id' => (int)$acik['id'], 'card_no' => (string)$u['card_no'],
                               'tip_id' => (int)$u['worker_type_id'], 'tip_adi' => (string)$u['tip_adi']];
            continue;
        }
        if ($acik) { $baska[] = (string)$u['card_no']; continue; }
        if ($d = pdks_ters_donmemis($pdo, $kid)) {
            if ((string)$d['work_date'] === $gun) $bugunEksik[] = (string)$u['card_no']; else $donmemis[] = (string)$u['card_no'];
            continue;
        }
        $stEk->execute([$kid]);
        if ((int)$stEk->fetchColumn() > 0) $eksikli[] = (string)$u['card_no'];
        if (!pdks_faz8j_desteklenen_tip($pdo, (int)$u['worker_type_id'])) $tipsiz[] = (string)$u['card_no'];
    }
    $liste = fn(array $k) => implode(', ', array_slice($k, 0, 10)) . (count($k) > 10 ? ' …' : '');
    $uyarilar = [];
    if ($baska) $uyarilar[] = count($baska) . ' kart başka bir mesaide içeride görünüyor (' . $liste($baska) . ') — bu kartlar akşam manuel çıkış yapamaz.';
    if ($donmemis) $uyarilar[] = count($donmemis) . ' kart önceki günden dönmedi (' . $liste($donmemis) . ') — elde değilse Kayıp işaretleyin; eski eksik çıkışı yönetici Mesai Detayı\'ndan düzeltmeli.';
    if ($eksikli) $uyarilar[] = count($eksikli) . ' kartın önceki bir mesaide düzeltilmemiş eksik çıkışı var (' . $liste($eksikli) . ') — akşam çıkışı yapılır, ancak eski kayıt yönetici tarafından Mesai Detayı\'ndan düzeltilmeli.';
    if ($bugunEksik) $uyarilar[] = count($bugunEksik) . ' kart bugün eksik çıkışla kapatılan bir mesaide kaldı (' . $liste($bugunEksik) . ') — bugün yeniden çıkış yapamaz.';
    if ($tipsiz) $uyarilar[] = count($tipsiz) . ' kartın havuz tipi pasif ya da desteklenmiyor (' . $liste($tipsiz) . ') — akşam çıkışı reddedilir; Kart Havuzu ekranından düzeltin.';
    if ($hoz['aktif'] === 0) $genel[] = 'Havuzda kullanılabilir kart yok.';
    if ($kalan !== null && $hoz['aktif'] > 0) {
        if ($kalan > $hoz['aktif']) {
            $genel[] = 'Elde kalan kart sayısı (' . $kalan . ') aktif havuzdan (' . $hoz['aktif'] . ') fazla olamaz.';
        } else {
            $oz['dagitilan'] = $hoz['aktif'] - $kalan;
            $oz['manuel_limit'] = $oz['dagitilan'] - $oz['zaten_iceride'];
            if ($oz['dagitilan'] < 1) $genel[] = 'Dağıtılmış kart yok (havuzdaki tüm kartlar elde) — onaylanacak sayım yok.';
            elseif ($oz['manuel_limit'] < 1) $genel[] = 'Dağıtılan kartların hepsi zaten normal okutmayla içeride — manuel sayıma gerek yok.';
        }
    }
    return ['ok' => !$genel && $kalan !== null, 'genel' => $genel, 'uyarilar' => $uyarilar, 'ozet' => $oz, 'session' => $mesai, 'zaten_kartlar' => $zatenKartlar];
}

/** Manuel sayım önizlemesi — HİÇBİR şey yazmaz. */
function pdks_ters_manuel_onizle(int $sayimId, $kalanHam, string $saatHam, string $neden, $beyanHam, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    $s = pdks_ters_sayim_getir($pdo, $sayimId);
    if (!$s) return pdks_ters_red('sayim_yok', 'Sayım bulunamadı.');
    if (!pdks_ters_depo_esit((string)$s['depo'], pdks_ters_aktif_depo())) return pdks_ters_red('yanlis_depo', 'Bu sayım aktif depoya ait değil.');
    if ((string)$s['durum'] !== 'taslak') return pdks_ters_red('sayim_kapali', pdks_ters_sayim_onayli_mi($s) ? 'Bu sayım zaten onaylandı.' : 'Bu sayım geri alınmış.');
    $k = pdks_ters_kalan_adet($kalanHam);
    $g = $saatHam !== '' ? pdks_ters_giris_coz($s, $saatHam, $neden) : ['ok' => true, 'zaman' => null];
    $b = pdks_ters_beyan($beyanHam);
    $d = pdks_ters_manuel_degerlendir($pdo, $s, $k['ok'] ? $k['kalan'] : null, $g['ok'] ? $g['zaman'] : null);
    if (!$k['ok']) { $d['genel'][] = $k['hata']; $d['ok'] = false; }
    if (!$g['ok']) { $d['genel'][] = $g['hata']; $d['ok'] = false; }
    if ($saatHam === '') $d['ok'] = false;   // onay için saat zorunlu
    if (!$b['ok']) { $d['genel'][] = $b['hata']; $d['ok'] = false; }
    elseif ($d['ozet']['dagitilan'] !== null && ($u = pdks_ters_beyan_uyarisi($b['beyan'], (int)$d['ozet']['dagitilan']))) $d['uyarilar'][] = $u;
    unset($d['session'], $d['zaten_kartlar']);
    return ['ok' => true, 'degerlendirme' => $d];
}

/**
 * MANUEL ONAY — tek transaction. Kilit sırası kartla onayla AYNI: sayım FOR UPDATE →
 * mesai FOR UPDATE → havuz üyelikleri + kartlar (artan id) → değerlendirme kilit ALTINDA.
 * Sabah DÖNEM YAZILMAZ; yalnız mesai (kiosk yolu) açılır ve sayım satırı 'manuel' olur.
 * Tekrar gönderim: aynı istek_id ile manuel onaylanmış sayım → aynı sonuç ('tekrar').
 * Yetki ÇAĞIRANDA (attendance.daily_scan).
 */
function pdks_ters_manuel_onayla(int $sayimId, $kalanHam, string $saatHam, string $neden, $beyanHam, string $istekIdHam, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo)) return pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı tabloları kurulmamış (migrate.php).');
    if ($pdo->inTransaction()) return pdks_ters_red('ic_ice_islem', 'Bu işlem başka bir işlemin içinde çalıştırılamaz.');
    $istekId = pdks_ters_istek_id($istekIdHam);
    if ($istekId === null) return pdks_ters_red('istek_gecersiz', 'Geçersiz istek anahtarı; sayfayı yenileyip tekrar deneyin.');
    $k = pdks_ters_kalan_adet($kalanHam);
    if (!$k['ok']) return $k;
    if ($k['kalan'] === null) return pdks_ters_red('kalan_bos', 'Elde kalan kart sayısını girin (hiç kalmadıysa 0).');
    $b = pdks_ters_beyan($beyanHam);
    if (!$b['ok']) return $b;
    $depoAktif = pdks_ters_aktif_depo();
    if ($depoAktif === '') return pdks_ters_red('depo_yok', 'Önce bir depo seçmelisiniz.');
    $simdi = date('Y-m-d H:i:s');
    $kilit = pdks_ters_kilit_ek($pdo);
    try {
        $pdo->beginTransaction();
        $s = pdks_ters_sayim_getir($pdo, $sayimId, true);
        if (!$s) throw new RuntimeException('Sayım bulunamadı.');
        if (pdks_ters_sayim_onayli_mi($s)) {
            $pdo->rollBack();
            if (pdks_ters_sayim_manuel_mi($s) && (string)$s['istek_id'] === $istekId) {
                return ['ok' => true, 'tekrar' => true, 'sayim_id' => $sayimId, 'session_id' => (int)$s['session_id'],
                        'ozet' => pdks_ters_sayim_ozet_satiri($s) + ['manuel_limit' => pdks_ters_manuel_limit($s)]];
            }
            return pdks_ters_red('zaten_onayli', 'Bu sayım başka bir işlemle zaten onaylandı.');
        }
        if ((string)$s['durum'] !== 'taslak') throw new RuntimeException('Bu sayım geri alınmış — yeni sayımı kullanın.');
        if (!pdks_ters_depo_esit((string)$s['depo'], $depoAktif)) throw new RuntimeException('Bu sayım aktif depoya ait değil.');
        if ((string)$s['work_date'] !== date('Y-m-d')) throw new RuntimeException('Bu sayım geçmiş bir güne ait — onaylanamaz.');
        $g = pdks_ters_giris_coz($s, $saatHam, $neden);
        if (!$g['ok']) throw new RuntimeException($g['hata']);
        $foremanId = (int)$s['foreman_id']; $gun = (string)$s['work_date']; $depo = (string)$s['depo'];
        // mesai satırı (KİLİTLİ) — kartla onayla aynı arama
        $stM = $pdo->prepare('SELECT id, depo FROM daily_work_sessions WHERE foreman_id = ? AND work_date = ?' . $kilit);
        $stM->execute([$foremanId, $gun]);
        $mesaiDepo = $depoAktif;
        foreach ($stM->fetchAll() as $m0) if (pdks_ters_depo_esit((string)$m0['depo'], $depo)) { $mesaiDepo = (string)$m0['depo']; break; }
        // üyelik + kart kilitleri (artan id) — "zaten içeride" sayısı kiosk girişiyle yarışmasın
        $stU = $pdo->prepare('SELECT worker_card_id, depo FROM ters_havuz_kart WHERE foreman_id = ? AND aktif_kart_id IS NOT NULL ORDER BY worker_card_id ASC' . $kilit);
        $stU->execute([$foremanId]);
        foreach ($stU->fetchAll() as $r) if (pdks_ters_depo_esit((string)$r['depo'], $depo)) pdks_gunluk_faz8a_kart_kilitle($pdo, (int)$r['worker_card_id']);

        $d = pdks_ters_manuel_degerlendir($pdo, $s, $k['kalan'], $g['zaman']);
        if (!$d['ok']) {
            $pdo->rollBack();
            return pdks_ters_red('onaylanamaz', $d['genel'] ? implode(' ', $d['genel']) : 'Manuel sayım onaylanamadı.', ['degerlendirme' => array_diff_key($d, ['session' => 1, 'zaten_kartlar' => 1])]);
        }
        $oc = pdks_faz8j_oturum_coz($pdo, $foremanId, $gun, $mesaiDepo, $user, true);
        if (!$oc['ok']) throw new RuntimeException((string)$oc['hata']);
        $sid = (int)$oc['oturum']['id'];
        if (pdks_faz8j_entitlement($pdo, $sid) === 'final') {
            throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi var — önce hakediş yönetici tarafından yeniden açılmalı.');
        }
        // Taslak okutmaları (kalan/teyit) — UNIQUE nedeniyle silinir, listesi audit'te kalır.
        $stT = $pdo->prepare('SELECT worker_card_id, rol, card_no_snapshot FROM ters_sayim_kart WHERE sayim_id = ? ORDER BY id ASC');
        $stT->execute([$sayimId]);
        $taslakOkutma = array_map(fn($r) => ['kart_id' => (int)$r['worker_card_id'], 'card_no' => (string)$r['card_no_snapshot'], 'rol' => (string)$r['rol']], $stT->fetchAll());
        if ($taslakOkutma) $pdo->prepare('DELETE FROM ters_sayim_kart WHERE sayim_id = ?')->execute([$sayimId]);
        // Zaten içeride (normal okutma) kartların anlık görüntüsü — kartla sayımdaki 'zaten_iceride' satırının
        // aynısı; mutabakat bu dönemleri sayar ki çıkış + içeride + eksik = dağıtılan tutsun.
        $insZ = $pdo->prepare('INSERT INTO ters_sayim_kart (sayim_id, worker_card_id, rol, worker_type_id_snapshot, worker_type_name_snapshot, card_no_snapshot, period_id, user_id) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($d['zaten_kartlar'] as $z) $insZ->execute([$sayimId, $z['kart_id'], 'zaten_iceride', $z['tip_id'], $z['tip_adi'], $z['card_no'], $z['period_id'], $user]);

        $oz = $d['ozet'];
        $pdo->prepare("UPDATE ters_sayim SET durum = ?, session_id = ?, giris_saati = ?, giris_zamani = ?, giris_saati_nedeni = ?,
                              havuz_aktif = ?, kalan_adet = ?, dagitilan_adet = ?, yeni_giris_adet = 0, zaten_iceride_adet = ?, haric_adet = ?,
                              beyan_adet = ?, istek_id = ?, onaylayan_user_id = ?, onay_zamani = ?
                        WHERE id = ? AND durum = 'taslak'")
            ->execute([PDKS_TERS_DURUM_MANUEL, $sid, $g['saat'], $g['zaman'], $g['neden'] !== '' ? $g['neden'] : null,
                       $oz['havuz_aktif'], $oz['kalan'], $oz['dagitilan'], $oz['zaten_iceride'], $oz['haric'],
                       $b['beyan'], $istekId, $user, $simdi, $sayimId]);
        $uyarilar = $d['uyarilar'];
        if ($bu = pdks_ters_beyan_uyarisi($b['beyan'], (int)$oz['dagitilan'])) $uyarilar[] = $bu;
        pdks_ters_audit($pdo, $user, 'ters_sayim_manuel_onay', 'ters_sayim', $sayimId, ['durum' => 'taslak'], [
            'istek_id' => $istekId, 'yontem' => 'manuel', 'foreman_id' => $foremanId, 'depo' => $depo, 'work_date' => $gun,
            'session_id' => $sid, 'yeni_mesai' => $oc['yeni'], 'giris_saati' => $g['saat'], 'varsayilan_giris_saati' => $s['varsayilan_giris_saati'],
            'giris_saati_nedeni' => $g['neden'], 'havuz_aktif' => $oz['havuz_aktif'], 'kalan' => $oz['kalan'], 'dagitilan' => $oz['dagitilan'],
            'zaten_iceride' => $oz['zaten_iceride'], 'manuel_limit' => $oz['manuel_limit'], 'haric' => $oz['haric'], 'beyan' => $b['beyan'],
            'silinen_taslak_okutma' => $taslakOkutma, 'onay_zamani' => $simdi,
        ]);
        $pdo->commit();
        return ['ok' => true, 'sayim_id' => $sayimId, 'session_id' => $sid, 'yeni_mesai' => $oc['yeni'],
                'ozet' => ['havuz_aktif' => $oz['havuz_aktif'], 'kalan' => $oz['kalan'], 'dagitilan' => $oz['dagitilan'], 'yeni_giris' => 0,
                           'zaten_iceride' => $oz['zaten_iceride'], 'haric' => $oz['haric'], 'manuel_limit' => $oz['manuel_limit'],
                           'giris_saati' => $g['saat'], 'onay_zamani' => $simdi],
                'uyarilar' => $uyarilar];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException && !$e instanceof PDOException) return pdks_ters_red('onaylanamaz', $e->getMessage());
        if (pdks_ters_eszamanli($e)) return pdks_ters_red('eszamanli', PDKS_TERS_ESZAMANLI);
        error_log('[pdks_ters_manuel_onayla] ' . $e->getMessage());
        return pdks_ters_red('yazma_hatasi', 'Onay sırasında teknik bir hata oluştu — hiçbir şey yazılmadı. Lütfen tekrar deneyin.');
    }
}

/**
 * Manuel sayımın geri alması — pdks_ters_sayim_geri_al() transaction'ı İÇİNDE, sayım satırı
 * kilitliyken çağrılır. Kural kartla sayımla aynı: İLK ÇIKIŞA kadar (bir kart bile manuel
 * çıkış yaptıysa geri alınamaz) ve mesai açıkken. İptal edilecek dönem YOKTUR (sabah dönem
 * yazılmadı); sayım 'geri_alindi' olur, aynı gün için BOŞ yeni taslak açılır.
 */
function pdks_ters_manuel_geri_al_tx(PDO $pdo, array $s, string $reason, int $user): array
{
    $sayimId = (int)$s['id']; $sid = (int)$s['session_id']; $depo = (string)$s['depo'];
    pdks_faz8j_oturum_kilitle($pdo, (int)$s['foreman_id'], (string)$s['work_date'], $depo);
    $st = $pdo->prepare('SELECT COUNT(*) FROM ters_sayim_kart WHERE sayim_id = ? AND rol = ?');
    $st->execute([$sayimId, PDKS_TERS_ROL_MANUEL_CIKIS]);
    $cikan = (int)$st->fetchColumn();
    if ($cikan > 0) {
        throw new RuntimeException('Çıkış okutulmaya başlandı (' . $cikan . ' kart) — manuel sayım artık geri alınamaz. Düzeltme yalnız yönetici tarafından Mesai Detayı\'ndan yapılır.');
    }
    $stS = $pdo->prepare('SELECT status FROM daily_work_sessions WHERE id = ?'); $stS->execute([$sid]);
    if ((string)$stS->fetchColumn() !== 'open') throw new RuntimeException('Mesai kapatılmış — sayım geri alınamaz.');
    $simdi = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE ters_sayim SET durum = 'geri_alindi', aktif_anahtar = NULL, geri_alan_user_id = ?, geri_alma_zamani = ?, geri_alma_nedeni = ? WHERE id = ? AND durum = ?")
        ->execute([$user, $simdi, $reason, $sayimId, PDKS_TERS_DURUM_MANUEL]);
    $yeniId = 0;
    if ((string)$s['work_date'] === date('Y-m-d')) {
        $pdo->prepare('INSERT INTO ters_sayim (foreman_id, depo, work_date, durum, aktif_anahtar, varsayilan_giris_saati, onceki_sayim_id, olusturan_user_id) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([(int)$s['foreman_id'], $depo, (string)$s['work_date'], 'taslak', pdks_ters_anahtar((int)$s['foreman_id'], (string)$s['work_date'], $depo),
                       $s['varsayilan_giris_saati'], $sayimId, $user]);
        $yeniId = (int)$pdo->lastInsertId();
    }
    pdks_ters_audit($pdo, $user, 'ters_sayim_geri_al', 'ters_sayim', $sayimId, ['durum' => PDKS_TERS_DURUM_MANUEL],
        ['durum' => 'geri_alindi', 'yontem' => 'manuel', 'reason' => $reason, 'iptal_edilen_donem' => 0, 'yeni_sayim_id' => $yeniId]);
    return ['ok' => true, 'iptal_edilen' => 0, 'yeni_sayim_id' => $yeniId];
}

/** Çavuş + depo için çıkış alabilecek manuel sayım (mesaisi AÇIK) id'si ya da 0. */
function pdks_ters_manuel_acik_sayim(PDO $pdo, int $foremanId, string $depo): int
{
    $st = $pdo->prepare("SELECT s.id, s.depo FROM ters_sayim s JOIN daily_work_sessions w ON w.id = s.session_id
                          WHERE s.foreman_id = ? AND s.durum = ? AND s.aktif_anahtar IS NOT NULL AND w.status = 'open'
                          ORDER BY s.work_date DESC, s.id DESC");
    $st->execute([$foremanId, PDKS_TERS_DURUM_MANUEL]);
    foreach ($st->fetchAll() as $r) if (pdks_ters_depo_esit((string)$r['depo'], $depo)) return (int)$r['id'];
    return 0;
}

/**
 * AKŞAM MANUEL ÇIKIŞ. Ortak Çıkış kartın açık dönemini bulamadığında ('acik_donem_yok')
 * çağrılır. Uygulanamıyorsa NULL döner (kart ters havuzda değil / başka depoda / çavuşun
 * mesaisi açık manuel sayımı yok) — çağıran ÖZGÜN hatayı gösterir. Uygulanıyorsa:
 * tek transaction, kilit sırası sayım → mesai → kart; limit, mükerrer, giriş saati ve
 * satır kuralları (pdks_faz8j_satir_kontrol) denetlenir; dönem sabit giriş + gerçek çıkış
 * saatiyle KAPALI yazılır (pdks_faz8j_satir_yaz, kaynak 'ters'); ters_sayim_kart'a
 * 'manuel_cikis' satırı (UNIQUE = ikinci çıkış engeli). Yanıt Ortak Çıkış başarı
 * yanıtıyla AYNI biçimde (+ 'manuel' sayaçları) — kiosk sonuç kartı aynen çalışır.
 */
function pdks_ters_manuel_cikis(string $ham, string $kaynak, int $user, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    if (!pdks_ters_sema_hazir($pdo) || $pdo->inTransaction()) return null;
    $u = pdks_ters_uid_coz($pdo, $ham, $kaynak);
    if (!$u['ok'] || $u['kart'] === null) return null;
    $kartId = (int)$u['kart']['id'];
    $depoAktif = pdks_ters_aktif_depo();
    $m = pdks_gunluk_ters_havuz_aktif($pdo, $kartId);
    if (!$m || $depoAktif === '' || !pdks_ters_depo_esit((string)$m['depo'], $depoAktif)) return null;
    $sayimId = pdks_ters_manuel_acik_sayim($pdo, (int)$m['foreman_id'], (string)$m['depo']);
    if ($sayimId === 0) return null;
    $simdi = date('Y-m-d H:i:s');
    $kilit = pdks_ters_kilit_ek($pdo);
    $red = function (string $kod, string $hata, array $ek = []) use ($pdo): array {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return pdks_ters_red($kod, $hata, $ek);
    };
    try {
        $pdo->beginTransaction();
        // 1 — sayım satırı (eşzamanlı iki terminal limiti birlikte aşamasın)
        $s = pdks_ters_sayim_getir($pdo, $sayimId, true);
        if (!$s || !pdks_ters_sayim_manuel_mi($s) || $s['aktif_anahtar'] === null) { $pdo->rollBack(); return null; }
        $sid = (int)$s['session_id'];
        // 2 — mesai satırı (Mesaiyi Kapat ile yarış)
        $stM = $pdo->prepare('SELECT s.*, COALESCE(f.name, s.foreman_name_snapshot) AS foreman_name FROM daily_work_sessions s LEFT JOIN foremen f ON f.id = s.foreman_id WHERE s.id = ?' . $kilit);
        $stM->execute([$sid]);
        $mesai = $stM->fetch();
        if (!$mesai || (string)$mesai['status'] !== 'open') { $pdo->rollBack(); return null; }
        // 3 — kart kilidi (kiosk giriş/çıkışıyla AYNI) + kilit altında yeniden okuma
        pdks_gunluk_faz8a_kart_kilitle($pdo, $kartId);
        $m = pdks_gunluk_ters_havuz_aktif($pdo, $kartId);
        if (!$m || (int)$m['foreman_id'] !== (int)$s['foreman_id'] || !pdks_ters_depo_esit((string)$m['depo'], (string)$s['depo'])) { $pdo->rollBack(); return null; }
        $stK = $pdo->prepare('SELECT * FROM worker_cards WHERE id = ?'); $stK->execute([$kartId]);
        $kart = $stK->fetch();
        if (pdks_gunluk_faz8a_kart_acik_donemi($pdo, $kartId)) {
            return $red('acik_donem_var', 'Kart ' . $kart['card_no'] . ' için az önce açık bir giriş oluştu — kartı tekrar okutun.');
        }
        $limit = pdks_ters_manuel_limit($s);
        // Mükerrer: bu sayımda kartın satırı var VE dönemi iptal edilmemiş. Dönemi yönetici tarafından
        // İPTAL edilmiş satır yeniden kullanılır (UNIQUE(sayim_id, worker_card_id) — aşağıda UPDATE).
        $stV = $pdo->prepare('SELECT k.id, k.rol, p.is_voided FROM ters_sayim_kart k LEFT JOIN daily_worker_work_periods p ON p.id = k.period_id WHERE k.sayim_id = ? AND k.worker_card_id = ?');
        $stV->execute([$sayimId, $kartId]);
        $mevcut = $stV->fetch() ?: null;
        if ($mevcut && !(int)($mevcut['is_voided'] ?? 0)) {
            return $red('mukerrer_cikis', 'Bu kart bugün zaten çıkış yaptı.', ['card_no' => (string)$kart['card_no']]);
        }
        $cikan = pdks_ters_manuel_cikan($pdo, $sayimId);
        if ($cikan >= $limit) {
            return $red('manuel_limit', 'Bu çavuşun manuel sayımına göre ' . $limit . ' kişi çalışıyor — ' . $cikan . ' çıkış tamamlandı, fazla çıkış yapılamaz.',
                        ['card_no' => (string)$kart['card_no'], 'manuel' => ['sayim_id' => $sayimId, 'dagitilan' => $limit, 'cikan' => $cikan, 'kalan' => 0]]);
        }
        $giris = (string)$s['giris_zamani'];
        if ($simdi <= $giris) {
            return $red('giris_saati_gelmedi', 'Bu kartın giriş saati (' . substr($giris, 11, 5) . ') henüz gelmedi — çıkış giriş saatinden önce yazılamaz.');
        }
        if (pdks_faz8j_entitlement($pdo, $sid) === 'final') {
            return $red('hakedis_kesin', 'Bu mesainin kesinleşmiş hakedişi var — çıkış yazılamaz. Hakediş yönetici tarafından yeniden açılmalı.');
        }
        $tip = pdks_faz8j_desteklenen_tip($pdo, (int)$m['worker_type_id']);
        if (!$tip) return $red('tip_desteklenmiyor', 'Kart ' . $kart['card_no'] . ' havuz tipi pasif ya da desteklenmiyor — Kart Havuzu ekranından düzeltin.');
        $satir = ['kartsiz' => false, 'kart' => $kart, 'tip' => $tip, 'entry' => $giris, 'exit' => $simdi];
        // 'kapali_mesai_eksik': kapatılmış eski mesaideki eksik çıkış sonsuza uzanmış sayılmaz (kartla sayımda
        // da kart o durumda yeni giriş yapabiliyor); AÇIK mesaideki çıkışsız dönem yine engeller.
        if ($h = pdks_faz8j_satir_kontrol($pdo, $sid, (string)$mesai['work_date'], $satir, ['foreman_id' => (int)$s['foreman_id'], 'depo' => (string)$mesai['depo']], ['kapali_mesai_eksik' => true])) {
            return $red('kart_uygun_degil', 'Kart ' . $kart['card_no'] . ': ' . $h, ['card_no' => (string)$kart['card_no']]);
        }
        $pid = pdks_faz8j_satir_yaz($pdo, $mesai, $satir, $user, [
            'reason' => PDKS_TERS_MANUEL_NOTU, 'note' => '', 'yeni_mesai' => false, 'simdi' => $simdi,
            'toplu_id' => 'TS' . $sayimId, 'kaynak' => PDKS_TERS_KAYNAK, 'olay_zamani' => $simdi, 'audit_action' => 'ters_manuel_cikis',
        ]);
        if ($mevcut) {   // dönemi iptal edilmiş eski satır — aynı kilitli tx içinde yeni döneme bağlanır
            $pdo->prepare('UPDATE ters_sayim_kart SET rol = ?, worker_type_id_snapshot = ?, worker_type_name_snapshot = ?, card_no_snapshot = ?, period_id = ?, okutma_kaynak = ?, okutma_zamani = ?, user_id = ? WHERE id = ?')
                ->execute([PDKS_TERS_ROL_MANUEL_CIKIS, (int)$tip['id'], (string)$tip['name'], (string)$kart['card_no'], $pid, $kaynak, $simdi, $user, (int)$mevcut['id']]);
        } else {
            $pdo->prepare('INSERT INTO ters_sayim_kart (sayim_id, worker_card_id, rol, worker_type_id_snapshot, worker_type_name_snapshot, card_no_snapshot, period_id, okutma_kaynak, okutma_zamani, user_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$sayimId, $kartId, PDKS_TERS_ROL_MANUEL_CIKIS, (int)$tip['id'], (string)$tip['name'], (string)$kart['card_no'], $pid, $kaynak, $simdi, $user]);
        }
        pdks_faz8j_yeniden_hesap_isaretle($pdo, $sid);
        $stE = $pdo->prepare('SELECT exit_event_id FROM daily_worker_work_periods WHERE id = ?'); $stE->execute([$pid]);
        $eventId = (int)$stE->fetchColumn();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (pdks_ters_eszamanli($e)) return pdks_ters_red('eszamanli', 'Bu kart aynı anda başka bir terminalden okutuldu — tekrar okutun.');
        error_log('[pdks_ters_manuel_cikis] ' . $e->getMessage());
        return pdks_ters_red('yazma_hatasi', 'Kayıt sırasında teknik bir hata oluştu. Lütfen tekrar deneyin.');
    }
    $cikan++;
    $workDate = (string)$mesai['work_date'];
    return [
        'ok' => true, 'event_id' => $eventId, 'event_type' => 'CIKIS',
        'card' => [
            'card_no' => (string)$kart['card_no'], 'worker_type_name' => (string)$tip['name'],
            'declared_class' => 'auto', 'declared_class_label' => pdks_gunluk_faz8a_mesai_siniflari()['auto'] ?? 'auto',
            'entry_time' => $giris, 'entry_source' => PDKS_TERS_KAYNAK,
        ],
        'server_time' => $simdi,
        'ozet' => pdks_gunluk_faz8a_oturum_ozet($sid, $pdo),
        'cavus' => ['id' => (int)$s['foreman_id'], 'ad' => (string)$mesai['foreman_name'], 'session_id' => $sid,
                    'work_date' => $workDate, 'onceki_gun' => $workDate < date('Y-m-d')],
        'manuel' => ['sayim_id' => $sayimId, 'dagitilan' => $limit, 'cikan' => $cikan, 'kalan' => max(0, $limit - $cikan)],
        'mesaj' => '🔢 Manuel sayım · ' . $cikan . '/' . $limit . ' çıkış',
    ];
}

/**
 * Ortak Çıkış yanıtına manuel sayım yedeği: özgün yanıt 'acik_donem_yok' ise ve kart
 * açık manuel sayımı olan bir çavuşun havuzundaysa manuel çıkış yazılır; değilse özgün
 * yanıt AYNEN döner. pdks_gunluk_ortak_cikis_kaydet()'in DIŞINDA çağrılır (o değişmez).
 */
function pdks_ters_ortak_cikis_manuel_yedek(array $sonuc, string $ham, string $kaynak, int $user, ?PDO $pdo = null): array
{
    if (!empty($sonuc['ok']) || ($sonuc['kod'] ?? '') !== 'acik_donem_yok') return $sonuc;
    try {
        $m = pdks_ters_manuel_cikis($ham, $kaynak, $user, $pdo);
    } catch (Throwable $e) {
        error_log('[pdks_ters_ortak_cikis_manuel_yedek] ' . $e->getMessage());
        return $sonuc;
    }
    return $m ?? $sonuc;
}

// =========================================================
// HIZLI ÇIKIŞ — mevcut ORTAK ÇIKIŞ'ın ince sarmalayıcısı (yazma YOK)
// =========================================================

/**
 * Kartın açık dönemini bulup çıkışı mevcut pdks_gunluk_ortak_cikis_kaydet() ile
 * yazar (o da değiştirilmemiş pdks_gunluk_faz8a_cikis_kaydet()'e devreder — gerçek
 * sunucu saati, kart kilidi, yanlış çavuş/depo reddi). Yanıta çavuşun "içeride"
 * sayısı ve dönemin ters sayımdan gelip gelmediği eklenir.
 */
function pdks_ters_cikis_kaydet(string $ham, string $kaynak, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $r = pdks_gunluk_ortak_cikis_kaydet($ham, $kaynak, $user, $pdo);
    $r = pdks_ters_ortak_cikis_manuel_yedek($r, $ham, $kaynak, $user, $pdo);   // v363: manuel sayım
    if (empty($r['ok'])) return $r;
    $sid = (int)($r['cavus']['session_id'] ?? 0);
    $mesailer = pdks_gunluk_ortak_cikis_mesailer(null, $pdo);
    $r['mesailer'] = $mesailer;
    foreach ($mesailer as $m) if ((int)$m['session_id'] === $sid) { $r['cavus']['icerde'] = (int)$m['icerde']; break; }
    $r['cavus']['icerde'] ??= 0;
    if (!empty($r['event_id'])) {
        $st = $pdo->prepare('SELECT source FROM daily_worker_work_periods WHERE exit_event_id = ?');
        $st->execute([(int)$r['event_id']]);
        $r['ters'] = (string)$st->fetchColumn() === PDKS_TERS_KAYNAK;
    }
    return $r;
}

// =========================================================
// GÜN SONU MUTABAKATI (salt okunur)
// =========================================================

/**
 * Gün + depo için çavuş çavuş mutabakat. Ayrı kavramlar (karıştırılmaz):
 *   dagitilan     — sabah sayımına göre dağıtılan kart (yeni giriş + zaten içeride)
 *   giris         — sayımın dönemlerinden iptal edilmemiş giriş kaydı
 *   cikis         — çıkışı okutulmuş (kart geri alınmış) dönem
 *   iceride       — çıkışsız, mesai AÇIK (işçi hâlâ içeride sayılır)
 *   eksik_cikis   — çıkışsız, mesai KAPATILMIŞ (çıkış uydurulmaz — yetkili düzeltme)
 *   donmeyen      — dağıtılmış ama henüz geri okutulmamış kartlar (iceride + eksik_cikis)
 *   iptal         — yönetici tarafından iptal edilmiş dönem
 * Ayrıca mesainin TÜM dönemleri (normal okutma dahil) ayrı sayılır.
 */
function pdks_ters_mutabakat(PDO $pdo, string $workDate, string $depo): array
{
    $durumlar = pdks_ters_aktif_durumlar();   // v363: 'manuel' dahil
    $st = $pdo->prepare('SELECT * FROM ters_sayim WHERE work_date = ? AND durum IN (' . implode(',', array_fill(0, count($durumlar), '?')) . ') ORDER BY id ASC');
    $st->execute(array_merge([$workDate], $durumlar));
    $sayimlar = [];
    foreach ($st->fetchAll() as $s) if (pdks_ters_depo_esit((string)$s['depo'], $depo)) $sayimlar[(int)$s['foreman_id']] = $s;
    $satirlar = [];
    $cavuslar = pdks_ters_havuzlu_cavuslar($pdo, $depo);
    $adlar = [];
    foreach ($cavuslar as $c) $adlar[$c['foreman_id']] = $c;
    foreach (array_keys($sayimlar) as $fid) if (!isset($adlar[$fid])) {
        $c = pdks_ters_cavus($pdo, $fid);
        $adlar[$fid] = ['foreman_id' => $fid, 'name' => $c ? (string)$c['name'] : '#' . $fid, 'havuz' => pdks_ters_havuz_ozet(pdks_ters_havuz_uyeleri($pdo, $fid, $depo))];
    }
    $etkin = pdks_gunluk_faz8j_etkin_kosul($pdo, 'p');
    foreach ($adlar as $fid => $c) {
        $s = $sayimlar[$fid] ?? null;
        $row = ['foreman_id' => $fid, 'foreman_name' => (string)$c['name'], 'havuz' => $c['havuz'], 'sayim' => null,
                'dagitilan' => 0, 'kalan' => 0, 'haric' => 0, 'giris' => 0, 'cikis' => 0, 'iceride' => 0, 'eksik_cikis' => 0, 'iptal' => 0,
                'donmeyen_kartlar' => [], 'teyitli' => 0, 'mesai' => null, 'mesai_toplam' => null,
                'yontem' => null, 'manuel' => null];
        $mesai = pdks_ters_bugunku_mesai($pdo, $fid, $workDate, $depo);
        if ($mesai) {
            $row['mesai'] = ['id' => (int)$mesai['id'], 'status' => (string)$mesai['status']];
            $oz = pdks_gunluk_faz8a_oturum_ozet((int)$mesai['id'], $pdo);
            $row['mesai_toplam'] = ['giris' => (int)$oz['giris_toplam'], 'cikis' => (int)$oz['cikis_toplam'], 'acik' => (int)$oz['eksik_toplam']];
        }
        if ($s) {
            $row['sayim'] = ['id' => (int)$s['id'], 'durum' => (string)$s['durum'], 'giris_saati' => $s['giris_saati'], 'onay_zamani' => $s['onay_zamani'],
                             'beyan' => $s['beyan_adet'] !== null ? (int)$s['beyan_adet'] : null];
            if (pdks_ters_sayim_manuel_mi($s)) {
                // v363 — MANUEL: dağıtılan kart listesi çıkışlardan oluşur; çıkışı okutulmayan
                // kişi için dönem/çıkış UYDURULMAZ (mesai açıkken "içeride", kapanınca "okutulmayan").
                $row['yontem'] = 'manuel';
                $row['dagitilan'] = (int)$s['dagitilan_adet']; $row['kalan'] = (int)$s['kalan_adet']; $row['haric'] = (int)$s['haric_adet'];
                $limit = pdks_ters_manuel_limit($s);
                $kartlar = [];
                foreach (pdks_ters_manuel_cikanlar($pdo, (int)$s['id']) as $p) {
                    if ((int)($p['is_voided'] ?? 0)) { $row['iptal']++; continue; }
                    $row['giris']++; $row['cikis']++;
                    $kartlar[] = ['card_no' => (string)$p['card_no'], 'tip' => (string)$p['tip'], 'cikis' => substr((string)$p['exit_time'], 11, 5)];
                }
                $manuelCikan = $row['cikis'];
                $okutulmayan = max(0, $limit - $manuelCikan);
                $acik = $mesai && (string)$mesai['status'] === 'open';
                // Zaten içeride (normal okutma) kartların dönemleri — onayda anlık görüntü (rol 'zaten_iceride').
                // Böylece çıkış + içeride + eksik çıkış = dağıtılan (kartla sayımla aynı mutabakat).
                $qz = $pdo->prepare("SELECT k.card_no_snapshot AS card_no, k.worker_type_name_snapshot AS tip, p.exit_time, p.is_voided
                                       FROM ters_sayim_kart k JOIN daily_worker_work_periods p ON p.id = k.period_id
                                      WHERE k.sayim_id = ? AND k.rol = 'zaten_iceride'");
                $qz->execute([(int)$s['id']]);
                $zatenAcik = 0;
                foreach ($qz->fetchAll() as $p) {
                    if ((int)($p['is_voided'] ?? 0)) { $row['iptal']++; continue; }
                    $row['giris']++;
                    if ($p['exit_time'] !== null) { $row['cikis']++; continue; }
                    $zatenAcik++;
                    $row['donmeyen_kartlar'][] = ['card_no' => (string)$p['card_no'], 'tip' => (string)$p['tip'], 'durum' => $acik ? 'İçeride' : 'Eksik çıkış'];
                }
                // Okutulmayan manuel kişi için dönem UYDURULMAZ: mesai açıkken "içeride", kapanınca "eksik çıkış" sayılır.
                if ($acik) $row['iceride'] = $okutulmayan + $zatenAcik; else $row['eksik_cikis'] = $okutulmayan + $zatenAcik;
                $row['manuel'] = ['limit' => $limit, 'zaten_iceride' => (int)$s['zaten_iceride_adet'], 'cikan' => $manuelCikan,
                                  'okutulmayan' => $okutulmayan, 'mesai_acik' => $acik, 'kartlar' => $kartlar];
            } elseif ((string)$s['durum'] === 'onayli') {
                $row['dagitilan'] = (int)$s['dagitilan_adet']; $row['kalan'] = (int)$s['kalan_adet']; $row['haric'] = (int)$s['haric_adet'];
                $q = $pdo->prepare(
                    "SELECT k.card_no_snapshot AS card_no, k.worker_type_name_snapshot AS tip, k.teyitli, p.exit_time, p.is_voided, s2.status AS s_status
                       FROM ters_sayim_kart k
                       JOIN daily_worker_work_periods p ON p.id = k.period_id
                       JOIN daily_work_sessions s2 ON s2.id = p.session_id
                      WHERE k.sayim_id = ? AND k.rol IN ('dagitilan','zaten_iceride')"
                );
                $q->execute([(int)$s['id']]);
                foreach ($q->fetchAll() as $p) {
                    if ((int)($p['is_voided'] ?? 0)) { $row['iptal']++; continue; }
                    if ((int)$p['teyitli']) $row['teyitli']++;
                    $row['giris']++;
                    if ($p['exit_time'] !== null) { $row['cikis']++; continue; }
                    if ((string)$p['s_status'] === 'open') $row['iceride']++; else $row['eksik_cikis']++;
                    $row['donmeyen_kartlar'][] = ['card_no' => (string)$p['card_no'], 'tip' => (string)$p['tip'],
                                                  'durum' => (string)$p['s_status'] === 'open' ? 'İçeride' : 'Eksik çıkış'];
                }
                usort($row['donmeyen_kartlar'], fn($a, $b) => strnatcasecmp($a['card_no'], $b['card_no']));
            }
        }
        $satirlar[] = $row;
    }
    usort($satirlar, fn($a, $b) => strcoll($a['foreman_name'], $b['foreman_name']));
    $toplam = ['dagitilan' => 0, 'giris' => 0, 'cikis' => 0, 'iceride' => 0, 'eksik_cikis' => 0, 'iptal' => 0];
    foreach ($satirlar as $r) foreach ($toplam as $k => $_) $toplam[$k] += (int)$r[$k];
    return ['satirlar' => $satirlar, 'toplam' => $toplam];
}

/** Ana sayfa (hub) çavuş kartları: havuz özeti + bugünkü sayım durumu + içeride. */
function pdks_ters_hub_durumlari(PDO $pdo, string $depo): array
{
    $bugun = date('Y-m-d');
    $mesailer = [];
    foreach (pdks_gunluk_ortak_cikis_mesailer($depo, $pdo) as $m) if ($m['work_date'] === $bugun) $mesailer[$m['foreman_id']] = $m;
    $out = [];
    foreach (pdks_ters_havuzlu_cavuslar($pdo, $depo) as $c) {
        $s = pdks_ters_sayim_aktif($pdo, $c['foreman_id'], $depo, $bugun);
        $sayim = $s ? ['id' => (int)$s['id'], 'durum' => (string)$s['durum'], 'dagitilan' => (int)$s['dagitilan_adet']] : null;
        $icerde = (int)($mesailer[$c['foreman_id']]['icerde'] ?? 0);
        if ($s && pdks_ters_sayim_manuel_mi($s)) {   // v363: manuel — içeride = çalışan − çıkan (dönem yok)
            $limit = pdks_ters_manuel_limit($s);
            $cikan = pdks_ters_manuel_cikan($pdo, (int)$s['id']);
            $sayim += ['manuel_limit' => $limit, 'cikan' => $cikan];
            if (pdks_ters_manuel_acik_sayim($pdo, (int)$c['foreman_id'], $depo) === (int)$s['id']) {   // mesai açık
                $icerde += max(0, $limit - $cikan);
            }
        }
        $out[] = $c + ['sayim' => $sayim, 'icerde' => $icerde];
    }
    return $out;
}

// =========================================================
// SAYFA YARDIMCILARI (ters_kart*.php)
// =========================================================

/** Kullanıcının modül yetkileri (yeni yetki YOK — mevcut Günlük İşçi yetkileri). */
function pdks_ters_yetkiler(): array
{
    return [
        'havuz'      => pdks_gunluk_can('worker_cards'),
        'tarama'     => pdks_gunluk_can('daily_scan'),
        'mutabakat'  => pdks_gunluk_can('daily_scan') || pdks_gunluk_can('daily_reports'),
        'yonetici'   => function_exists('is_admin') && is_admin(),
    ];
}

/**
 * Sayfa kapısı: modül açık + oturum + aktif depo (enforce_active_depot) + yetki
 * ($gerekli içindeki anahtarlardan EN AZ biri). require_pdks_gunluk() ile aynı sıra.
 */
function pdks_ters_require(array $gerekli): array
{
    if (!PDKS_GUNLUK_AKTIF) {
        if (function_exists('forbidden')) forbidden('Günlük işçi modülü şu anda kapalıdır.');
        http_response_code(503); exit('Günlük işçi modülü kapalı.');
    }
    if (function_exists('current_user') && current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    if (function_exists('enforce_active_depot')) enforce_active_depot();
    $y = pdks_ters_yetkiler();
    foreach ($gerekli as $g) if (!empty($y[$g])) return $y;
    forbidden('Bu sayfaya erişim yetkiniz yok. (Giriş Tanımlı)');
    return $y;
}

/** JSON uç yanıtı + çıkış. */
function pdks_ters_json(array $r, int $kod = 200): void
{
    if ($kod !== 200) http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON POST gövdesi + CSRF (csrf_check JSON-aware: 403 + JSON). */
function pdks_ters_json_govde(): array
{
    $g = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($g)) $g = [];
    csrf_check(is_string($g['csrf'] ?? null) ? $g['csrf'] : null);
    return $g;
}

/** Üst sekme şeridi (yetkiye göre). $aktif: hub|havuz|sayim|cikis|mutabakat */
function pdks_ters_sekmeler(string $aktif, array $y): void
{
    $s = [
        'hub'       => ['ters_kart.php', '↩ Özet', true],
        'havuz'     => ['ters_kart_havuz.php', '🗂 Kart Havuzu', $y['havuz']],
        'sayim'     => ['ters_kart_sayim.php', '🌅 Sabah Sayımı', $y['tarama']],
        // v355: çıkış TEK noktadan — Ortak Çıkış (ters + normal girişler; kaynak sonuçta görünür).
        'cikis'     => ['gunluk_isci_giris_cikis.php?mod=ortak', '🚪 Ortak Çıkış', $y['tarama']],
        'mutabakat' => ['ters_kart_mutabakat.php', '📋 Mutabakat', $y['mutabakat']],
    ];
    echo '<nav class="tk-sekmeler" aria-label="Giriş Tanımlı">';
    foreach ($s as $k => [$url, $ad, $izin]) {
        if (!$izin) continue;
        echo '<a href="' . h($url) . '" class="tk-sekme' . ($k === $aktif ? ' aktif" aria-current="page' : '') . '">' . h($ad) . '</a>';
    }
    echo '</nav>';
}

/** Modül kurulmamışsa sayfa gövdesine bilgi kutusu basar (true = kurulmamış). */
function pdks_ters_kurulum_uyarisi(PDO $pdo): bool
{
    if (pdks_ters_sema_hazir($pdo)) return false;
    echo '<div class="tk-kutu tk-kutu-bilgi"><strong>Giriş Tanımlı henüz kurulmamış.</strong> '
       . 'Bir yöneticinin <a href="migrate.php">migrate.php</a> sayfasındaki "Giriş Tanımlı Tabloları" kartından kurulumu yapması gerekir. '
       . 'Mevcut giriş-çıkış sistemi bundan etkilenmez.</div>';
    return true;
}

/** Ortak onay penceresi (native dialog — TK.sor() doldurur). $ek: pencereye eklenecek ek alan HTML'i (güvenli, sabit). */
function pdks_ters_dialog(string $ek = ''): void
{
    echo '<dialog id="tkDlg" class="tk-dlg" aria-labelledby="tkDlgBaslik">'
       . '<div class="tk-dlg-govde">'
       . '<h2 id="tkDlgBaslik" data-tk-dlg-baslik></h2>'
       . '<p data-tk-dlg-metin class="muted"></p>'
       . $ek
       . '<div data-tk-dlg-alan hidden><label class="tk-etiket"><span data-tk-dlg-et>Gerekçe</span>'
       . '<textarea data-tk-dlg-gerekce rows="3" maxlength="500"></textarea></label>'
       . '<div data-tk-dlg-hata class="tk-kutu tk-kutu-hata" hidden></div></div>'
       . '</div>'
       . '<div class="tk-dlg-alt"><button type="button" class="btn" data-tk-dlg-vazgec>Vazgeç</button>'
       . '<button type="button" class="btn btn-primary" data-tk-dlg-tamam>Onayla</button></div>'
       . '</dialog>';
}

/** Modül CSS/JS bağlantıları (filemtime sürümlü). */
function pdks_ters_varliklar(string $base): void
{
    echo '<link rel="stylesheet" href="' . h($base) . 'assets/pdks.css?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/pdks.css') . '">';
}
function pdks_ters_script(string $base): void
{
    echo '<script src="' . h($base) . 'assets/ters_kart.js?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/ters_kart.js') . '"></script>';
}
