<?php
// =========================================================
// config/pdks_hakedis.php — ÇAVUŞ HAKEDİŞİ (Günlük İşçi, Faz 4) ÇEKİRDEĞİ
//
// İş modeli (kullanıcı açıklaması, Sprint Günlük-İşçi-04):
//   Şirket çavuşla GÜNLÜK KİŞİ BAŞI bir fiyat üzerinde anlaşır (işçi tipine
//   göre — Kadın/Erkek/gelecekteki tipler). Bir günün hakedişi:
//     Σ (o gün o çavuşta GEÇERLİ GİRİŞ yapan BENZERSİZ kart sayısı × o
//        tarihte GEÇERLİ birim fiyat), işçi tipi bazında.
//
// ⚠ MİMARİ İLKE (kullanıcının açık talimatı): "Phase 4 consumes Phase 3
// attendance data. It must not create a parallel attendance truth." Bu
// dosya KENDİ tarama/sayım SQL'ini YAZMAZ — tek sayım kaynağı
// config/pdks_gunluk.php'deki pdks_gunluk_oturum_kart_sayimi() (ve durum
// için pdks_gunluk_oturum_ozet()/pdks_gunluk_oturum_durumu()). Bu dosya
// yalnız PARA hesabını ekler.
//
// Hakediş/fiyat/ödeme HENÜZ YOK denen Faz 1-3'ün TAM TERSİNE, bu fazın
// KONUSU budur — ama ödeme/cari/fatura/genel muhasebe HÂLÂ YOK (kullanıcının
// açık talimatı, sonraki faza bırakıldı). Bu dosya yalnız:
//   1) çavuş+işçi-tipi+tarih bazlı GÜNLÜK ÜCRET (etkin tarihli) master'ı
//   2) bir mesainin hakediş HESABI (taslak) + KESİNLEŞTİRME (final) — donmuş
//      finansal satır/toplam
//
// ⚠ BU DOSYA config/db.php / config/helpers.php TARAFINDAN YÜKLENMEZ —
//    config/pdks_gunluk.php'nin AYNI gerekçesi: buradaki bir hata
//    uygulamanın geri kalanını ASLA etkilememelidir. pdks_hakedis_migrate()
//    de kendiliğinden çalışmaz — yalnız migrate.php'den AÇIKÇA çağrılır.
//
// ⚠ config/pdks_gunluk.php'YE TEK YÖNLÜ, SERT BAĞIMLILIK (bilinçli — Faz
//    1'in pdks.php↔pdks_gunluk.php çapraz YUMUŞAK bağımlılığından FARKLI):
//    hakediş sayfaları HER ZAMAN config/pdks_gunluk.php'yi de yükler
//    (foremen/worker_types/daily_work_sessions/daily_worker_card_events
//    olmadan hakediş hesaplanamaz zaten) — pdks_gunluk.php ASLA bu dosyayı
//    geriye doğru çağırmaz, yön TEK taraflıdır.
// =========================================================

declare(strict_types=1);

defined('PDKS_HAKEDIS_AKTIF') || define('PDKS_HAKEDIS_AKTIF', true);

// =========================================================
// PARA — DECIMAL/KURUŞ (BİNARY FLOAT YOK)
// =========================================================
//
// ⚠ Kullanıcının açık talimatı: "In PHP do NOT introduce floating-point
// rounding errors... Do not calculate financial totals with binary
// float." Bu ortamda BCMath YÜKLÜ DEĞİL (php -m ile doğrulandı) — bu
// yüzden TAM SAYI KURUŞ stratejisi kullanılır: TÜM çarpma/toplama İŞLEMLERİ
// int kuruş üzerinde yapılır (int×int PHP'de TAM sonuç verir, ondalık
// hata YOKTUR), yalnız DB'ye YAZARKEN/OKURKEN DECIMAL(…,2) string'e
// dönüştürülür. Girdi ayrıştırma (metin → kuruş) TAMAMEN string/regex
// tabanlıdır — ara adımda TEK BİR float bile üretilmez.
function pdks_hakedis_tl_kurus(string $tl): int
{
    $tl = trim($tl);
    $neg = str_starts_with($tl, '-');
    if ($neg) $tl = substr($tl, 1);
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $tl, $m)) {
        throw new InvalidArgumentException('Geçersiz DECIMAL tutar: ' . $tl);
    }
    $kurus = ((int)$m[1]) * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    return $neg ? -$kurus : $kurus;
}
function pdks_hakedis_kurus_tl(int $kurus): string
{
    $neg = $kurus < 0;
    $kurus = abs($kurus);
    $s = sprintf('%d.%02d', intdiv($kurus, 100), $kurus % 100);
    return $neg ? '-' . $s : $s;
}
/** Kullanıcı girdisi (form) → kuruş. Virgül ONDALIK ayracı olarak kabul
 *  edilir (Türkçe klavye), binlik ayraç YOK (bu formda gerek yok — küçük
 *  kapsam, kullanıcının "keep implementation simple" talimatı). Geçersizse
 *  NULL döner (0 ÜRETMEZ — çağıran taraf bunu bir doğrulama hatası olarak
 *  ele almalı, asla varsayılan ücret olarak YUTMAMALI). */
function pdks_hakedis_girdi_kurus(string $ham): ?int
{
    $ham = trim(str_replace(',', '.', $ham));
    if ($ham === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $ham)) return null;
    return pdks_hakedis_tl_kurus($ham);
}

// =========================================================
// ŞEMA
// =========================================================

function pdks_hakedis_tablolar(): array
{
    $t = [];

    // ── foreman_worker_rates — çavuş × işçi tipi ETKİN TARİHLİ günlük ücret ──
    // ⚠ Fiyat worker_types'a DEĞİL buraya konur (kullanıcının açık talimatı:
    // "Do NOT put one global female/male rate into worker_types. Rates
    // belong commercially to the FOREMAN.") — aynı işçi tipi için çavuştan
    // çavuşa FARKLI ücret olabilir.
    // ⚠ ÇAKIŞMA ÖNLEME: MySQL iki tarih aralığının binişmediğini garanti
    // eden bir EXCLUDE/UNIQUE kısıtı SUNMAZ (PostgreSQL'in EXCLUDE USING
    // gist'inin dengi yok). "En küçük güvenli uygulama" (kullanıcının açık
    // talimatı): TEK yazma yolu (pdks_hakedis_oran_ekle()) yeni bir dönem
    // eklenirken AYNI çavuş+tip için önceki AÇIK UÇLU/binişen dönemi
    // otomatik olarak yeni başlangıcın BİR GÜN ÖNCESİNDE kapatır — bu
    // yüzden binişen iki aktif dönem YAPISAL OLARAK hiç oluşmaz, ayrı bir
    // DB kısıtı gerekmez (bkz. fonksiyon docblock'u).
    $t['foreman_worker_rates'] = "CREATE TABLE IF NOT EXISTS `foreman_worker_rates` (
        `id`                 INT AUTO_INCREMENT PRIMARY KEY,
        `foreman_id`         INT           NOT NULL,
        `worker_type_id`     INT           NOT NULL,
        `daily_rate`         DECIMAL(12,2) NOT NULL,
        `currency`           VARCHAR(10)   NOT NULL DEFAULT 'TRY',
        `valid_from`         DATE          NOT NULL,
        `valid_to`           DATE          NULL DEFAULT NULL,
        `is_active`          TINYINT(1)    NOT NULL DEFAULT 1,
        `created_by_user_id` INT           NULL DEFAULT NULL,
        `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`         DATETIME      NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_fwr_foreman_type_from` (`foreman_id`, `worker_type_id`, `valid_from`),
        INDEX `idx_fwr_active` (`is_active`),
        CONSTRAINT `fk_fwr_foreman` FOREIGN KEY (`foreman_id`)
            REFERENCES `foremen`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT `fk_fwr_type` FOREIGN KEY (`worker_type_id`)
            REFERENCES `worker_types`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ── foreman_daily_entitlements — bir OTURUMUN hakediş kaydı (taslak/final) ──
    // UNIQUE(session_id): oturum başına TEK hakediş kaydı — DRAFT yeniden
    // hesaplanınca YENİ satır İCAT EDİLMEZ, aynı kayıt güncellenir (aşağıya
    // bkz., pdks_hakedis_hesapla()). foreman_name/code_snapshot: Faz 3'ün
    // AYNI ilkesi — çavuş adı SONRADAN değişse bile GEÇMİŞ hakediş raporu
    // sessizce değişmesin.
    $t['foreman_daily_entitlements'] = "CREATE TABLE IF NOT EXISTS `foreman_daily_entitlements` (
        `id`                    INT AUTO_INCREMENT PRIMARY KEY,
        `session_id`            INT           NOT NULL,
        `foreman_id`            INT           NOT NULL,
        `foreman_name_snapshot` VARCHAR(150)  NOT NULL DEFAULT '',
        `foreman_code_snapshot` VARCHAR(20)   NOT NULL DEFAULT '',
        `work_date`             DATE          NOT NULL,
        `depo`                  VARCHAR(150)  NOT NULL DEFAULT '',
        `status`                VARCHAR(20)   NOT NULL DEFAULT 'draft',
        `currency`              VARCHAR(10)   NOT NULL DEFAULT 'TRY',
        `total_amount`          DECIMAL(14,2) NOT NULL DEFAULT 0,
        `calculated_at`         DATETIME      NOT NULL,
        `calculated_by_user_id` INT           NULL DEFAULT NULL,
        `finalized_at`          DATETIME      NULL DEFAULT NULL,
        `finalized_by_user_id`  INT           NULL DEFAULT NULL,
        `missing_exit_ack`      TINYINT(1)    NOT NULL DEFAULT 0,
        `notes`                 TEXT          NULL DEFAULT NULL,
        `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`            DATETIME      NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_fde_session` (`session_id`),
        INDEX `idx_fde_status`   (`status`),
        INDEX `idx_fde_date`     (`work_date`),
        INDEX `idx_fde_foreman`  (`foreman_id`),
        CONSTRAINT `fk_fde_session` FOREIGN KEY (`session_id`)
            REFERENCES `daily_work_sessions`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT `fk_fde_foreman` FOREIGN KEY (`foreman_id`)
            REFERENCES `foremen`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ── foreman_daily_entitlement_lines — FİNANSAL DONDURULMUŞ satırlar ──
    // worker_type_name_snapshot: olayın KENDİ worker_type_name_snapshot'ından
    // KOPYALANIR (tarama anının gerçeği). worker_type_code_snapshot: hesap
    // ANINDA worker_types'tan ÇÖZÜLÜR ve BURAYA yazılır — worker_types'ın
    // KENDİSİ hiç ALTER edilmediği (bkz. pdks_gunluk.php, kod/ad değiştirme
    // fonksiyonu YOK) için bu iki alan pratikte AYNI garantiyi taşır: bir
    // kez FİNAL olduktan sonra bu satır BİR DAHA hiç okunup yeniden
    // yazılmaz, dolayısıyla sonraki HERHANGİ bir canlı değişiklik (varsayımsal
    // bir "tip yeniden adlandır" özelliği eklense bile) bu satırı ETKİLEMEZ.
    // worker_type_id'YE BİLEREK FK KONULMADI (worker_type_id_snapshot ile
    // AYNI ilke — tarihi referans, canlı bütünlüğe bağımlı olmamalı).
    $t['foreman_daily_entitlement_lines'] = "CREATE TABLE IF NOT EXISTS `foreman_daily_entitlement_lines` (
        `id`                        INT AUTO_INCREMENT PRIMARY KEY,
        `entitlement_id`            INT           NOT NULL,
        `worker_type_id`            INT           NULL DEFAULT NULL,
        `worker_type_code_snapshot` VARCHAR(30)   NOT NULL DEFAULT '',
        `worker_type_name_snapshot` VARCHAR(80)   NOT NULL DEFAULT '',
        `worker_count`              INT           NOT NULL,
        `unit_rate`                 DECIMAL(12,2) NOT NULL,
        `line_total`                DECIMAL(14,2) NOT NULL,
        `created_at`                DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_fdel_entitlement` (`entitlement_id`),
        CONSTRAINT `fk_fdel_entitlement` FOREIGN KEY (`entitlement_id`)
            REFERENCES `foreman_daily_entitlements`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $t;
}

/** pdks_gunluk_tablo_var() ile AYNI desen, bilerek KOPYALANDI (bkz. başlık). */
function pdks_hakedis_tablo_var(PDO $pdo, string $tablo): bool
{
    try { $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

/**
 * Şema migrasyonu — IDEMPOTENT, yıkıcı DEĞİL, YALNIZ additive CREATE TABLE.
 * Faz 1-3 tablolarına (foremen/worker_types/daily_work_sessions/
 * daily_worker_card_events) HİÇ DOKUNMAZ, ALTER/DROP UYGULAMAZ (kullanıcının
 * açık talimatı: "treat Phase 4 as an ADDITIVE upgrade... Do not assume
 * existing tables are absent. Do not drop/recreate Phase 1-3 tables.").
 *
 * ⚠ KENDİLİĞİNDEN ÇALIŞMAZ — yalnız migrate.php'nin kontrollü admin
 *    aksiyonundan çağrılır (pdks_gunluk_migrate() ile AYNI ilke).
 */
function pdks_hakedis_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $rapor = [];
    foreach (pdks_hakedis_tablolar() as $ad => $sql) {
        if (pdks_hakedis_tablo_var($pdo, $ad)) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.'];
            continue;
        }
        try {
            $pdo->exec($sql);
            $rapor[] = pdks_hakedis_tablo_var($pdo, $ad)
                ? ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']
                : ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'CREATE çalıştı ama tablo görünmüyor.'];
        } catch (PDOException $e) {
            error_log('[pdks_hakedis_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => $e->getMessage()];
        }
    }
    return $rapor;
}

function pdks_hakedis_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    foreach (array_keys(pdks_hakedis_tablolar()) as $ad) {
        if (!pdks_hakedis_tablo_var($pdo, $ad)) return false;
    }
    return true;
}

/** pdks_gunluk_sayfa_kapisi() ile AYNI desen (bkz. o dosyanın docblock'u) —
 *  şema HAZIR DEĞİLSE sayfayı GÜVENLE, açık bir admin mesajıyla SONLANDIRIR. */
function pdks_hakedis_sayfa_kapisi(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    if (pdks_hakedis_sema_hazir($pdo)) return;

    $mesaj = 'Hakediş modülü tabloları henüz oluşturulmamış. Bir yöneticinin '
           . 'migrate.php sayfasından "Hakediş Tablolarını Oluştur" demesi gerekiyor.';
    if (function_exists('set_flash')) set_flash('error', $mesaj);
    if (function_exists('render_header')) render_header('Hakediş');
    if (function_exists('render_flash')) {
        render_flash();
    } elseif (function_exists('h')) {
        echo '<div class="flash flash-error">' . h($mesaj) . '</div>';
    }
    if (function_exists('render_footer')) render_footer();
    exit;
}

// =========================================================
// YETKİ KAPISI
//
// ⚠ Yalnız İKİ İZİN (kullanıcının açık talimatı örneği): attendance.
// foreman_rates, attendance.entitlements. 'entitlements_finalize' AYRI bir
// İZİN DEĞİL — İKİ MEVCUT iznin KESİŞİMİ: ik rolü attendance.entitlements
// alır ama attendance.foreman_rates ALMAZ (bkz. helpers.php) — bu yüzden
// GÖREBİLİR/TASLAK hesaplayabilir ama KESİNLEŞTİREMEZ. "ik rolü ticari
// oranları yönetmemeli ama hakedişi görebilmeli" kuralı YENİ bir izin
// icat etmeden, yalnız İKİ mevcut iznin birleşimiyle ifade edilir.
// =========================================================

function pdks_hakedis_can(string $eylem): bool
{
    if (!function_exists('can')) return false;
    if (function_exists('is_admin') && is_admin()) return true;

    return match ($eylem) {
        'rates'                 => can('attendance.foreman_rates'),
        'entitlements_view'     => can('attendance.entitlements'),
        'entitlements_finalize' => can('attendance.entitlements') && can('attendance.foreman_rates'),
        default                 => false,
    };
}

function require_pdks_hakedis(string $eylem): void
{
    if (!PDKS_HAKEDIS_AKTIF) {
        if (function_exists('forbidden')) forbidden('Hakediş modülü şu anda kapalıdır.');
        http_response_code(503);
        exit('Hakediş modülü kapalı.');
    }
    if (function_exists('current_user') && current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    if (function_exists('enforce_active_depot')) enforce_active_depot();
    if (!pdks_hakedis_can($eylem)) {
        forbidden("Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: {$eylem})");
    }
}

// =========================================================
// FİYAT (foreman_worker_rates) — ETKİN TARİHLİ, TEK YAZMA YOLU
// =========================================================

/**
 * YENİ bir etkin dönem ekler — bu tablonun TEK yazma yoludur (UPDATE ile
 * eski ücreti YERİNDE değiştirme YOK, kullanıcının açık talimatı: "Do NOT
 * simply UPDATE the old commercial rate in a way that destroys history.").
 *
 * ÇAKIŞMA ÖNLEME: aynı çavuş+tip için MEVCUT en son (valid_from'u en büyük)
 * aktif dönem bulunur:
 *   - yeni valid_from, o dönemin valid_from'undan SONRA olmalı (aksi hâlde
 *     geriye dönük/belirsiz sıralama REDDEDİLİR — "smallest safe
 *     implementation": dönemler her zaman KRONOLOJİK sırayla eklenir);
 *   - mevcut dönem hâlâ AÇIK UÇLU (valid_to NULL) veya yeni başlangıçla
 *     BİNİŞİYORSA, valid_to yeni başlangıçtan BİR GÜN ÖNCEYE otomatik
 *     kapatılır (ücreti DEĞİŞTİRMEZ, yalnızca geçerlilik penceresini kapar).
 * Bu ikisi birlikte, üst üste binen iki AKTİF dönemin hiçbir zaman
 * OLUŞAMAYACAĞINI yapısal olarak garanti eder — ayrı bir DB kısıtı gerekmez.
 */
function pdks_hakedis_oran_ekle(int $foremanId, int $workerTypeId, string $gunlukUcretHam, string $validFrom, ?string $currency, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $currency = trim((string)$currency) ?: 'TRY';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) || !strtotime($validFrom)) {
        return ['ok' => false, 'hata' => 'Geçerlilik başlangıç tarihi geçersiz.'];
    }
    $kurus = pdks_hakedis_girdi_kurus($gunlukUcretHam);
    if ($kurus === null || $kurus <= 0) {
        return ['ok' => false, 'hata' => 'Günlük ücret geçersiz. Örnek: 1200 veya 1200,50'];
    }

    $stCavus = $pdo->prepare("SELECT id FROM foremen WHERE id = ?");
    $stCavus->execute([$foremanId]);
    if (!$stCavus->fetchColumn()) return ['ok' => false, 'hata' => 'Çavuş bulunamadı.'];
    $stTip = $pdo->prepare("SELECT id FROM worker_types WHERE id = ?");
    $stTip->execute([$workerTypeId]);
    if (!$stTip->fetchColumn()) return ['ok' => false, 'hata' => 'İşçi tipi bulunamadı.'];

    $stMevcut = $pdo->prepare(
        "SELECT * FROM foreman_worker_rates
          WHERE foreman_id = ? AND worker_type_id = ? AND is_active = 1
          ORDER BY valid_from DESC, id DESC LIMIT 1"
    );
    $stMevcut->execute([$foremanId, $workerTypeId]);
    $mevcut = $stMevcut->fetch();

    if ($mevcut && strtotime((string)$mevcut['valid_from']) >= strtotime($validFrom)) {
        return ['ok' => false, 'hata' => 'Yeni geçerlilik başlangıcı (' . $validFrom . '), mevcut en son dönemin başlangıcından (' . $mevcut['valid_from'] . ') SONRA olmalıdır.'];
    }

    if ($mevcut && ((string)$mevcut['valid_to'] === '' || $mevcut['valid_to'] === null || strtotime((string)$mevcut['valid_to']) >= strtotime($validFrom))) {
        $bitis = date('Y-m-d', strtotime($validFrom . ' -1 day'));
        $pdo->prepare("UPDATE foreman_worker_rates SET valid_to = ? WHERE id = ?")->execute([$bitis, (int)$mevcut['id']]);
    }

    $ins = $pdo->prepare(
        "INSERT INTO foreman_worker_rates (foreman_id, worker_type_id, daily_rate, currency, valid_from, valid_to, is_active, created_by_user_id)
         VALUES (?,?,?,?,?,NULL,1,?)"
    );
    $ins->execute([$foremanId, $workerTypeId, pdks_hakedis_kurus_tl($kurus), $currency, $validFrom, $userId]);
    $id = (int)$pdo->lastInsertId();

    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'foreman_worker_rates', $id, null, [
            'foreman_id' => $foremanId, 'worker_type_id' => $workerTypeId,
            'daily_rate' => pdks_hakedis_kurus_tl($kurus), 'currency' => $currency, 'valid_from' => $validFrom,
        ]);
    }
    return ['ok' => true, 'id' => $id];
}

/** Bir çavuş+tip için verilen tarihte GEÇERLİ oranı döner, yoksa NULL —
 *  ASLA 0 üretmez, ASLA başka bir çavuşun oranına düşmez (kullanıcının
 *  açık talimatı: "Do not silently use 0. Do not guess. Do not fall back
 *  to another foreman's rate."). */
function pdks_hakedis_oran_gecerli(int $foremanId, int $workerTypeId, string $tarih, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare(
        "SELECT * FROM foreman_worker_rates
          WHERE foreman_id = ? AND worker_type_id = ? AND is_active = 1
            AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
          ORDER BY valid_from DESC LIMIT 1"
    );
    $st->execute([$foremanId, $workerTypeId, $tarih, $tarih]);
    return $st->fetch() ?: null;
}

/** cavus_fiyatlari.php için — bir çavuşun TÜM ücret geçmişi (işçi tipi
 *  adı JOIN ile — bu CANLI worker_types.name'dir, tarihi bir SNAPSHOT
 *  DEĞİLDİR: bu sayfa bir YÖNETİM ekranıdır, geçmiş bir finansal rapor
 *  değil — finansal donmuş görünüm için bkz. hakediş satırlarındaki
 *  worker_type_name_snapshot). Aktif/pasif FARK ETMEKSİZİN TÜMÜ listelenir
 *  — kullanıcının açık talimatı: "Do not delete financially referenced
 *  historical rates" — silinmez, listede KALIR. */
function pdks_hakedis_oran_gecmisi(int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare(
        "SELECT r.*, wt.name AS worker_type_name, wt.code AS worker_type_code
           FROM foreman_worker_rates r
           JOIN worker_types wt ON wt.id = r.worker_type_id
          WHERE r.foreman_id = ?
          ORDER BY wt.name ASC, r.valid_from DESC"
    );
    $st->execute([$foremanId]);
    return $st->fetchAll();
}

// =========================================================
// HAKEDİŞ (foreman_daily_entitlements / …_lines) — TASLAK ↔ KESİN
// =========================================================

/**
 * Bir OTURUMUN hakedişini (yeniden) HESAPLAR — TASLAK. Faz 2/3'ün TEK sayım
 * kaynağını (pdks_gunluk_oturum_kart_sayimi()) kullanır, KENDİ tarama
 * mantığını YAZMAZ. Oranı OLMAYAN işçi tipleri için satır İCAT ETMEZ (0
 * yazmaz) — bunun yerine 'eksik_tipler' listesinde AÇIKÇA döner; KESİN'e
 * geçiş bunu SIKI biçimde reddeder (bkz. pdks_hakedis_finalize()).
 *
 * ⚠ Zaten KESİNLEŞMİŞ bir kayıt varsa REDDEDİLİR — "Do NOT allow automatic
 * recalculation of FINAL hakediş." Yeniden açmak İÇİN bkz.
 * pdks_hakedis_yeniden_ac().
 */
function pdks_hakedis_hesapla(int $sessionId, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id = ?");
    $st->execute([$sessionId]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'];

    $stE = $pdo->prepare("SELECT * FROM foreman_daily_entitlements WHERE session_id = ?");
    $stE->execute([$sessionId]);
    $mevcut = $stE->fetch();
    if ($mevcut && $mevcut['status'] === 'final') {
        return ['ok' => false, 'kod' => 'zaten_kesinlesmis', 'hata' => 'Bu mesainin hakedişi zaten KESİNLEŞMİŞ — otomatik yeniden hesaplanamaz.'];
    }

    $sayim = function_exists('pdks_gunluk_oturum_kart_sayimi') ? pdks_gunluk_oturum_kart_sayimi($sessionId, $pdo) : [];
    if (!$sayim) {
        return ['ok' => false, 'kod' => 'kart_yok', 'hata' => 'Bu mesaide hiç GİRİŞ kaydı yok — hesaplanacak işçi yok.'];
    }

    $simdi = date('Y-m-d H:i:s');   // ⚠ SUNUCU saati — istemciden bir zaman ASLA alınmaz.
    $satirlar = []; $toplamKurus = 0; $eksikTipler = [];
    $stKod = $pdo->prepare("SELECT code FROM worker_types WHERE id = ?");
    foreach ($sayim as $s) {
        $tipId = $s['tip_id'] !== null ? (int)$s['tip_id'] : null;
        $ad    = (string)$s['tip_ad'];
        $adet  = (int)$s['n'];
        if ($tipId === null) {
            $eksikTipler[] = $ad !== '' ? $ad : 'Bilinmeyen tip';
            continue;
        }
        $oran = pdks_hakedis_oran_gecerli((int)$oturum['foreman_id'], $tipId, (string)$oturum['work_date'], $pdo);
        if ($oran === null) {
            $eksikTipler[] = $ad;
            continue;
        }
        $stKod->execute([$tipId]);
        $kod = (string)($stKod->fetchColumn() ?: '');

        $birimKurus  = pdks_hakedis_tl_kurus((string)$oran['daily_rate']);
        $satirKurus  = $birimKurus * $adet;   // ⚠ int × int — TAM sonuç, ondalık hata YOK.
        $toplamKurus += $satirKurus;
        $satirlar[] = [
            'worker_type_id' => $tipId,
            'worker_type_code_snapshot' => $kod,
            'worker_type_name_snapshot' => $ad,
            'worker_count' => $adet,
            'unit_rate' => pdks_hakedis_kurus_tl($birimKurus),
            'line_total' => pdks_hakedis_kurus_tl($satirKurus),
        ];
    }

    if ($mevcut) {
        $pdo->prepare("DELETE FROM foreman_daily_entitlement_lines WHERE entitlement_id = ?")->execute([(int)$mevcut['id']]);
        $upd = $pdo->prepare(
            "UPDATE foreman_daily_entitlements
                SET status='draft', total_amount=?, calculated_at=?, calculated_by_user_id=?, updated_at=?
              WHERE id=?"
        );
        $upd->execute([pdks_hakedis_kurus_tl($toplamKurus), $simdi, $userId, $simdi, (int)$mevcut['id']]);
        $entId = (int)$mevcut['id'];
    } else {
        $ins = $pdo->prepare(
            "INSERT INTO foreman_daily_entitlements
                (session_id, foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo,
                 status, currency, total_amount, calculated_at, calculated_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $ins->execute([
            $sessionId, $oturum['foreman_id'], $oturum['foreman_name_snapshot'] ?? '', $oturum['foreman_code_snapshot'] ?? '',
            $oturum['work_date'], $oturum['depo'], 'draft', 'TRY', pdks_hakedis_kurus_tl($toplamKurus), $simdi, $userId,
        ]);
        $entId = (int)$pdo->lastInsertId();
    }

    $insL = $pdo->prepare(
        "INSERT INTO foreman_daily_entitlement_lines
            (entitlement_id, worker_type_id, worker_type_code_snapshot, worker_type_name_snapshot, worker_count, unit_rate, line_total)
         VALUES (?,?,?,?,?,?,?)"
    );
    foreach ($satirlar as $sl) {
        $insL->execute([$entId, $sl['worker_type_id'], $sl['worker_type_code_snapshot'], $sl['worker_type_name_snapshot'], $sl['worker_count'], $sl['unit_rate'], $sl['line_total']]);
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('calculate', 'foreman_daily_entitlements', $entId, null, [
            'session_id' => $sessionId, 'total_amount' => pdks_hakedis_kurus_tl($toplamKurus), 'eksik_tipler' => $eksikTipler,
        ]);
    }

    return [
        'ok' => true, 'entitlement_id' => $entId, 'status' => 'draft',
        'total_amount' => pdks_hakedis_kurus_tl($toplamKurus), 'lines' => $satirlar, 'eksik_tipler' => $eksikTipler,
    ];
}

/**
 * TASLAĞI KESİNLEŞTİRİR. Sıkı kurallar (kullanıcının açık talimatı):
 *   - oturum KAPALI olmalı (açık mesaide yalnız önizleme/taslak serbest);
 *   - eksik çıkış VARSA açık bir onay bayrağı ($eksikCikisOnayi) ZORUNLU;
 *   - kullanılan HER işçi tipi için GEÇERLİ bir oran bulunmalı, yoksa
 *     KESİNLEŞTİRME reddedilir (0 ÜRETİLMEZ).
 * Kesinleşme ANINDA en güncel veriyle YENİDEN hesaplanır (rate'ler taslak
 * oluşturulduktan sonra değişmiş olabilir) — bu, "taslak KESİNLEŞENE kadar
 * güncel veriyi yansıtabilir" kuralının doğal SONUCUDUR.
 */
function pdks_hakedis_finalize(int $sessionId, int $userId, bool $eksikCikisOnayi, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id = ?");
    $st->execute([$sessionId]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'];

    if ((string)$oturum['status'] !== 'closed') {
        return ['ok' => false, 'kod' => 'oturum_acik',
                 'hata' => 'Mesai AÇIK — hakediş yalnız KAPALI mesai için KESİNLEŞTİRİLEBİLİR. Önce mesaiyi kapatın.'];
    }

    if (!function_exists('pdks_gunluk_oturum_ozet')) {
        return ['ok' => false, 'kod' => 'gunluk_yuklu_degil', 'hata' => 'Günlük İşçi modülü yüklü değil.'];
    }
    $ozet = pdks_gunluk_oturum_ozet($sessionId, $pdo);
    $eksikToplam = (int)($ozet['eksik_toplam'] ?? 0);
    if ($eksikToplam > 0 && !$eksikCikisOnayi) {
        return ['ok' => false, 'kod' => 'eksik_cikis_onay_gerekli',
                 'hata' => 'Bu mesai eksik çıkışla kapatılmıştır. Devam etmek için açık onay gerekir.',
                 'eksik_toplam' => $eksikToplam];
    }

    $hesap = pdks_hakedis_hesapla($sessionId, $userId, $pdo);
    if (!$hesap['ok']) return $hesap;

    if (!empty($hesap['eksik_tipler'])) {
        $tarihGoster = date('d.m.Y', strtotime((string)$oturum['work_date']));
        $ilkEksik = $hesap['eksik_tipler'][0];
        return ['ok' => false, 'kod' => 'oran_eksik',
                 'hata' => $ilkEksik . ' işçi tipi için ' . $tarihGoster . ' tarihinde geçerli fiyat bulunamadı.',
                 'eksik_tipler' => $hesap['eksik_tipler']];
    }

    $simdi = date('Y-m-d H:i:s');
    $upd = $pdo->prepare(
        "UPDATE foreman_daily_entitlements
            SET status='final', finalized_at=?, finalized_by_user_id=?, missing_exit_ack=?, updated_at=?
          WHERE id=?"
    );
    $upd->execute([$simdi, $userId, $eksikToplam > 0 ? 1 : 0, $simdi, (int)$hesap['entitlement_id']]);

    if (function_exists('audit_log_event')) {
        audit_log_event('finalize', 'foreman_daily_entitlements', (int)$hesap['entitlement_id'], null, [
            'session_id' => $sessionId, 'total_amount' => $hesap['total_amount'], 'eksik_cikis_onayi' => $eksikCikisOnayi,
        ]);
    }

    return ['ok' => true, 'entitlement_id' => (int)$hesap['entitlement_id'], 'status' => 'final', 'total_amount' => $hesap['total_amount']];
}

/**
 * KESİNLEŞMİŞ bir hakedişi DRAFT'a geri açar — kullanıcının açık talimatı:
 * "require explicit controlled reopen/cancel strategy". Kasıtlı olarak
 * SIKI: yalnız is_admin() (records.unlock/hesap.admin ile AYNI ilke — bir
 * finansal kilidi açmak "kesinleştirmekten" daha yüksek bir yetki ister)
 * VE zorunlu, boş olmayan bir GEREKÇE (revision_reason ile AYNI desen,
 * bkz. records.unlock). Satırları SESSİZCE silmez/değiştirmez — yalnız
 * durumu draft'a döndürür; yeni bir pdks_hakedis_hesapla() çağrısı (AÇIKÇA,
 * ayrı bir eylem olarak) satırları yeniden yazar.
 *
 * ⚠ FAZ 5 KORUMASI (görev talimatı madde 11 — "A FINAL entitlement that has
 * already entered current-account history cannot silently disappear or
 * change without financial trace"): KESİN bir hakediş, çavuşun cari
 * hesabının bir PARÇASI olur (bkz. config/pdks_cari.php — bakiye/ekstre
 * doğrudan bu tablodan TÜRETİLİR, ayrı bir defter YOK). Bu çavuşun EN AZ
 * bir GEÇERLİ (iptal edilmemiş) ödemesi VARSA, bu hakedişi yeniden açmak
 * o ödemenin dayandığı geçmiş bakiyeyi SESSİZCE değiştirebilirdi — "en
 * küçük güvenli kural" (kullanıcının açık talimatı, seçenek A: "blocking
 * unsafe reopen is acceptable") burada YENİDEN AÇMAYI TAMAMEN ENGELLEMEKTİR,
 * karmaşık bir ters-kayıt (reversal) motoru KURMAK DEĞİL. Faz 4 dosyası
 * Faz 5'e SERT bağımlı OLAMAZ (yön TEK taraflı, bkz. dosya başlığı) — bu
 * yüzden config/pdks.php↔pdks_gunluk.php İLE AYNI YUMUŞAK (function_exists)
 * çapraz kontrol deseni kullanılır: pdks_cari.php YÜKLÜYSE devreye girer,
 * YÜKLÜ DEĞİLSE (Faz 5 tabloları henüz yoksa/modül hiç çağrılmadıysa) bu
 * kontrol sessizce ATLANIR — Faz 4 TEK BAŞINA hiçbir zaman Faz 5'e SERT
 * bağımlı olmaz.
 */
function pdks_hakedis_yeniden_ac(int $entitlementId, string $sebep, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!function_exists('is_admin') || !is_admin()) {
        return ['ok' => false, 'kod' => 'yetkisiz', 'hata' => 'Kesinleşmiş hakedişi yeniden açmak yalnızca sistem yöneticisine açıktır.'];
    }
    $sebep = trim($sebep);
    if ($sebep === '') {
        return ['ok' => false, 'kod' => 'gerekce_zorunlu', 'hata' => 'Yeniden açma gerekçesi zorunludur.'];
    }
    $st = $pdo->prepare("SELECT * FROM foreman_daily_entitlements WHERE id = ?");
    $st->execute([$entitlementId]);
    $ent = $st->fetch();
    if (!$ent) return ['ok' => false, 'kod' => 'hakedis_yok', 'hata' => 'Hakediş kaydı bulunamadı.'];
    if ($ent['status'] !== 'final') return ['ok' => false, 'kod' => 'zaten_taslak', 'hata' => 'Bu hakediş zaten TASLAK durumda.'];

    if (function_exists('pdks_cari_odeme_var_mi') && pdks_cari_odeme_var_mi((int)$ent['foreman_id'], $pdo)) {
        return ['ok' => false, 'kod' => 'cari_hareketli_engel',
                 'hata' => 'Bu çavuşun cari hesabında en az bir GEÇERLİ ödeme kaydı olduğu için bu hakediş yeniden AÇILAMAZ '
                         . '— geçmiş bakiye sessizce değişmez. Düzeltme gerekiyorsa yeni bir muhasebe düzeltme akışı gerekir (Faz 5 kapsamı dışı).'];
    }

    $simdi = date('Y-m-d H:i:s');
    $notlar = trim((string)$ent['notes']);
    $notlar = ($notlar !== '' ? $notlar . "\n" : '') . '[' . $simdi . '] Yeniden açma gerekçesi: ' . $sebep;
    $upd = $pdo->prepare(
        "UPDATE foreman_daily_entitlements
            SET status='draft', finalized_at=NULL, finalized_by_user_id=NULL, notes=?, updated_at=?
          WHERE id=?"
    );
    $upd->execute([$notlar, $simdi, $entitlementId]);

    if (function_exists('audit_log_event')) {
        audit_log_event('reopen', 'foreman_daily_entitlements', $entitlementId, $ent, ['sebep' => $sebep]);
    }
    return ['ok' => true];
}

/** cavus_hakedis_detay.php için — bir hakedişin satırları. */
function pdks_hakedis_satirlar(int $entitlementId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT * FROM foreman_daily_entitlement_lines WHERE entitlement_id = ? ORDER BY worker_type_name_snapshot ASC");
    $st->execute([$entitlementId]);
    return $st->fetchAll();
}

/**
 * cavus_hakedis.php için — bir work_date (+ opsiyonel depo/çavuş/durum)
 * için TÜM hakediş kayıtları. Faz 3'ün pdks_gunluk_gun_listesi()'ndeki AYNI
 * N+1-siz ilke: TEK sorgu, sayfa kendi SQL'ini yazmaz.
 *
 * ⚠ $durum: 'draft'|'final' (entitlement.status) veya null=tümü. Bu, henüz
 * hiç hesaplanmamış (session var ama entitlement satırı YOK) oturumları
 * KAPSAMAZ — cavus_hakedis.php ayrıca pdks_gunluk_gun_listesi()'nden gelen
 * oturumlarla LEFT JOIN mantığıyla "henüz hesaplanmadı" satırlarını KENDİSİ
 * gösterir (aşağıya bkz., sayfa dosyası).
 */
function pdks_hakedis_gun_listesi(string $workDate, ?string $depo = null, ?int $foremanId = null, ?string $durum = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $where = ['work_date = ?']; $params = [$workDate];
    if ($depo !== null && $depo !== '') { $where[] = 'depo = ?'; $params[] = $depo; }
    if ($foremanId !== null) { $where[] = 'foreman_id = ?'; $params[] = $foremanId; }
    if ($durum !== null && $durum !== '') { $where[] = 'status = ?'; $params[] = $durum; }
    $st = $pdo->prepare(
        "SELECT * FROM foreman_daily_entitlements WHERE " . implode(' AND ', $where) . "
          ORDER BY foreman_name_snapshot ASC"
    );
    $st->execute($params);
    return $st->fetchAll();
}
